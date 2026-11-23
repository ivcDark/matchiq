<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    leagues: { type: Array, default: () => [] },
    matches: { type: Array, default: () => [] },
    selectedLeagues: { type: Array, default: () => ['all'] },
    /** yesterday | today | tomorrow | null (произвольная дата из календаря) */
    period: {
        default: 'today',
        validator: (v) => v === null || ['yesterday', 'today', 'tomorrow'].includes(v),
    },
    /** Выбранный день YYYY-MM-DD */
    date: { type: String, default: null },
    widgets: {
        type: Object,
        default: () => ({
            league: null,
            standings: [],
            scorers: [],
            hotScorers: [],
            news: [],
        }),
    },
});

const periods = [
    { id: 'yesterday', label: 'Вчера' },
    { id: 'today', label: 'Сегодня' },
    { id: 'tomorrow', label: 'Завтра' },
];

const WEEKDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

const calendarOpen = ref(false);
const calendarMonth = ref(null);
const calendarRoot = ref(null);

/**
 * Коды flagcdn.com для табов стран.
 * Англия → gb-eng (не UK), Европа → eu. Без emoji — они ломаются на части ОС.
 */
const COUNTRY_FLAG_CODES = {
    Россия: 'ru',
    Англия: 'gb-eng',
    Испания: 'es',
    Италия: 'it',
    Германия: 'de',
    Франция: 'fr',
    Европа: 'eu',
    Нидерланды: 'nl',
};

function countryFlagUrl(country) {
    const code = COUNTRY_FLAG_CODES[country];
    return code ? `https://flagcdn.com/w20/${code}.png` : null;
}

function countryFlagSrcset(country) {
    const code = COUNTRY_FLAG_CODES[country];
    return code ? `https://flagcdn.com/w40/${code}.png 2x` : null;
}

const mobileWidgetTabs = [
    { id: 'standings', label: 'Таблица' },
    { id: 'scorers', label: 'Бомбардиры' },
    { id: 'hot', label: 'Скорострелы' },
    { id: 'news', label: 'Новости' },
];

const soonHint = ref(null);
const mobileWidget = ref('standings');
/** Активная страна в горизонтальных табах (не аккордеон). */
const activeCountry = ref(null);
let soonTimer = null;

function parseYmd(ymd) {
    if (!ymd || typeof ymd !== 'string') return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
    if (!m) return null;
    return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
}

function formatYmd(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function startOfLocalDay(d = new Date()) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

function addDays(d, n) {
    const next = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    next.setDate(next.getDate() + n);
    return next;
}

const selectedDate = computed(() => {
    const fromProp = parseYmd(props.date);
    if (fromProp) return fromProp;

    const today = startOfLocalDay();
    if (props.period === 'yesterday') return addDays(today, -1);
    if (props.period === 'tomorrow') return addDays(today, 1);
    return today;
});

const selectedDateKey = computed(() => formatYmd(selectedDate.value));

const isCustomDate = computed(
    () => props.period !== 'yesterday' && props.period !== 'today' && props.period !== 'tomorrow',
);

const calendarMonthLabel = computed(() => {
    const base = calendarMonth.value ?? selectedDate.value;
    return new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(base);
});

const calendarDays = computed(() => {
    const base = calendarMonth.value ?? selectedDate.value;
    const year = base.getFullYear();
    const month = base.getMonth();
    const first = new Date(year, month, 1);
    // Пн=0 … Вс=6
    const startPad = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayKey = formatYmd(startOfLocalDay());
    const selectedKey = selectedDateKey.value;
    const cells = [];

    for (let i = 0; i < startPad; i += 1) {
        cells.push({ key: `pad-${i}`, empty: true });
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        const d = new Date(year, month, day);
        const key = formatYmd(d);
        cells.push({
            key,
            empty: false,
            day,
            date: d,
            isToday: key === todayKey,
            isSelected: key === selectedKey,
        });
    }

    return cells;
});

function syncCalendarMonth() {
    const d = selectedDate.value;
    calendarMonth.value = new Date(d.getFullYear(), d.getMonth(), 1);
}

watch(
    () => props.date,
    () => {
        if (calendarOpen.value) {
            syncCalendarMonth();
        }
    },
);

const liveLeagueIds = computed(
    () => new Set(props.leagues.filter((l) => l.status === 'live').map((l) => l.id)),
);

const leagueById = computed(() => {
    const map = new Map();
    for (const league of props.leagues) {
        map.set(league.id, league);
    }
    return map;
});

const isAllMode = computed(
    () => props.selectedLeagues.length === 1 && props.selectedLeagues[0] === 'all',
);

const selectedLeagueIds = computed(() => {
    if (isAllMode.value) {
        return [];
    }
    return props.selectedLeagues.filter((id) => liveLeagueIds.value.has(id));
});

const leaguesByCountry = computed(() => {
    const order = [];
    const map = new Map();
    const selected = new Set(selectedLeagueIds.value);

    for (const league of props.leagues) {
        const country = league.country || 'Другие';
        if (!map.has(country)) {
            const group = {
                country,
                leagues: [],
                hasLive: false,
                selectedCount: 0,
            };
            map.set(country, group);
            order.push(group);
        }
        const group = map.get(country);
        group.leagues.push(league);
        if (league.status === 'live') {
            group.hasLive = true;
        }
        if (selected.has(league.id)) {
            group.selectedCount += 1;
        }
    }

    return order;
});

const activeCountryGroup = computed(
    () => leaguesByCountry.value.find((g) => g.country === activeCountry.value) ?? null,
);

const activeCountryLeagues = computed(
    () => activeCountryGroup.value?.leagues ?? [],
);

const selectedChips = computed(() => {
    if (isAllMode.value) {
        return [{ id: 'all', label: 'Все', removable: false }];
    }
    return selectedLeagueIds.value.map((id) => {
        const league = leagueById.value.get(id);
        return {
            id,
            label: league?.short || league?.name || id,
            removable: true,
        };
    });
});

const leagueHint = computed(() => {
    if (isAllMode.value) {
        const n = liveLeagueIds.value.size;
        if (n === 0) {
            return 'Нет активных лиг';
        }
        if (n === 1) {
            const only = props.leagues.find((l) => l.status === 'live');
            return only ? `Все · ${only.country} · ${only.name}` : 'Все активные';
        }
        return `Все активные · ${n}`;
    }
    if (selectedLeagueIds.value.length === 0) {
        return null;
    }
    if (selectedLeagueIds.value.length === 1) {
        const league = leagueById.value.get(selectedLeagueIds.value[0]);
        if (!league) {
            return null;
        }
        return `${league.country} · ${league.name}`;
    }
    return selectedLeagueIds.value
        .map((id) => {
            const league = leagueById.value.get(id);
            return league?.short || league?.name || id;
        })
        .join(' · ');
});

const filteredMatches = computed(() => {
    const dayKey = selectedDateKey.value;

    return props.matches.filter((m) => {
        if (isAllMode.value) {
            if (!liveLeagueIds.value.has(m.league_id)) {
                return false;
            }
        } else if (!selectedLeagueIds.value.includes(m.league_id)) {
            return false;
        }

        return kickoffDateKey(m.kickoff_at) === dayKey;
    });
});

const matchesByLeague = computed(() => {
    const map = new Map();

    for (const match of filteredMatches.value) {
        const leagueId = match.league_id || 'unknown';
        if (!map.has(leagueId)) {
            const league = leagueById.value.get(leagueId);
            map.set(leagueId, {
                key: leagueId,
                label: league?.name || league?.short || leagueId,
                sortOrder: typeof league?.sort_order === 'number' ? league.sort_order : Number.MAX_SAFE_INTEGER,
                matches: [],
            });
        }
        map.get(leagueId).matches.push(match);
    }

    const groups = Array.from(map.values());

    groups.sort((a, b) => {
        if (a.sortOrder !== b.sortOrder) {
            return a.sortOrder - b.sortOrder;
        }
        return a.label.localeCompare(b.label, 'ru');
    });

    for (const group of groups) {
        group.matches.sort((a, b) => {
            const statusDiff = statusSortRank(a.status) - statusSortRank(b.status);
            if (statusDiff !== 0) {
                return statusDiff;
            }
            return kickoffSortValue(a.kickoff_at) - kickoffSortValue(b.kickoff_at);
        });
    }

    return groups;
});

const hasLiveLeagues = computed(() => liveLeagueIds.value.size > 0);

const widgetLeagueLabel = computed(
    () => props.widgets?.league?.label ?? leagueHint.value ?? 'Лига',
);

function isLeagueSelected(leagueId) {
    return selectedLeagueIds.value.includes(leagueId);
}

function pickDefaultCountry() {
    const groups = leaguesByCountry.value;
    if (groups.length === 0) {
        return null;
    }

    // Страна первой выбранной лиги, иначе первая live, иначе первая в списке.
    for (const id of selectedLeagueIds.value) {
        const league = leagueById.value.get(id);
        if (league?.country) {
            return league.country;
        }
    }

    const liveGroup = groups.find((g) => g.hasLive);
    return liveGroup?.country ?? groups[0].country;
}

function syncActiveCountry() {
    const groups = leaguesByCountry.value;
    if (groups.length === 0) {
        activeCountry.value = null;
        return;
    }

    const stillValid = groups.some((g) => g.country === activeCountry.value);
    if (!stillValid) {
        activeCountry.value = pickDefaultCountry();
    }
}

watch(
    () => [props.leagues, props.selectedLeagues],
    () => syncActiveCountry(),
    { immediate: true },
);

function setActiveCountry(country) {
    activeCountry.value = country;
}

function currentLeaguesParam() {
    return isAllMode.value ? ['all'] : selectedLeagueIds.value;
}

function selectAll() {
    if (isAllMode.value) {
        return;
    }
    navigate({ leagues: ['all'] });
}

function selectLeague(league) {
    if (league.status !== 'live') {
        showSoonHint();
        return;
    }

    let next;
    if (isAllMode.value) {
        // С «Все» → только эта лига.
        next = [league.id];
    } else if (isLeagueSelected(league.id)) {
        // Повторный клик снимает лигу; последняя → «Все».
        next = selectedLeagueIds.value.filter((id) => id !== league.id);
        if (next.length === 0) {
            next = ['all'];
        }
    } else {
        next = [...selectedLeagueIds.value, league.id];
    }

    navigate({ leagues: next });
}

function removeLeague(leagueId) {
    if (leagueId === 'all') {
        return;
    }
    const next = selectedLeagueIds.value.filter((id) => id !== leagueId);
    navigate({
        leagues: next.length === 0 ? ['all'] : next,
    });
}

function selectPeriod(periodId) {
    calendarOpen.value = false;
    if (periodId === props.period && !isCustomDate.value) {
        return;
    }
    navigate({
        leagues: currentLeaguesParam(),
        period: periodId,
    });
}

function toggleCalendar() {
    calendarOpen.value = !calendarOpen.value;
    if (calendarOpen.value) {
        syncCalendarMonth();
    }
}

function shiftCalendarMonth(delta) {
    const base = calendarMonth.value ?? selectedDate.value;
    calendarMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}

function selectCalendarDay(day) {
    if (!day || day.empty) return;
    calendarOpen.value = false;

    const key = day.key;
    const today = startOfLocalDay();
    const yesterday = formatYmd(addDays(today, -1));
    const todayKey = formatYmd(today);
    const tomorrow = formatYmd(addDays(today, 1));

    if (key === yesterday) {
        navigate({ leagues: currentLeaguesParam(), period: 'yesterday' });
        return;
    }
    if (key === todayKey) {
        navigate({ leagues: currentLeaguesParam(), period: 'today' });
        return;
    }
    if (key === tomorrow) {
        navigate({ leagues: currentLeaguesParam(), period: 'tomorrow' });
        return;
    }

    navigate({ leagues: currentLeaguesParam(), date: key });
}

function onDocumentPointerDown(event) {
    if (!calendarOpen.value || !calendarRoot.value) return;
    if (!calendarRoot.value.contains(event.target)) {
        calendarOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    clearTimeout(soonTimer);
});

/**
 * @param {{ leagues?: string[], period?: string|null, date?: string|null }} opts
 */
function navigate({ leagues, period, date } = {}) {
    const nextLeagues = leagues ?? currentLeaguesParam();
    const leagueParam = nextLeagues.length === 1 && nextLeagues[0] === 'all'
        ? 'all'
        : nextLeagues.join(',');

    const query = { league: leagueParam };

    if (date) {
        query.date = date;
    } else if (period) {
        query.period = period;
    } else if (isCustomDate.value && props.date) {
        query.date = props.date;
    } else {
        query.period = props.period || 'today';
    }

    router.get(
        route('matches.index'),
        query,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function showSoonHint() {
    soonHint.value = 'Лига в разработке — скоро';
    clearTimeout(soonTimer);
    soonTimer = setTimeout(() => {
        soonHint.value = null;
    }, 2200);
}

function statusLabel(status) {
    if (status === 'live') return 'Идёт';
    if (status === 'finished') return 'Завершён';
    return 'Ожидается';
}

/** Внутри лиги: не начались → идут → завершены. */
function statusSortRank(status) {
    if (status === 'live') return 1;
    if (status === 'finished') return 2;
    return 0;
}

function kickoffSortValue(iso) {
    if (!iso) return Number.MAX_SAFE_INTEGER;
    const t = new Date(iso).getTime();
    return Number.isNaN(t) ? Number.MAX_SAFE_INTEGER : t;
}

function formatKickoff(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return new Intl.DateTimeFormat('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

function kickoffDateKey(iso) {
    if (!iso) return 'unknown';
    return formatYmd(new Date(iso));
}

function scoreText(match) {
    if (match.score_home == null || match.score_away == null) {
        return null;
    }
    return `${match.score_home}∶${match.score_away}`;
}

function topProb(match) {
    const vals = [
        { key: '1', value: match.prob_home },
        { key: 'X', value: match.prob_draw },
        { key: '2', value: match.prob_away },
    ].filter((v) => typeof v.value === 'number');

    if (vals.length === 0) return null;
    vals.sort((a, b) => b.value - a.value);
    return vals[0];
}

function gdText(gd) {
    if (gd > 0) return `+${gd}`;
    return String(gd);
}

const listKey = computed(
    () => `${props.selectedLeagues.join(',')}-${selectedDateKey.value}`,
);
</script>

<template>
    <Head title="Матчи" />

    <AppLayout>
        <!-- Tournament / league menu ABOVE matches -->
        <section class="animate-miq-fade-up" style="animation-delay: 60ms" aria-label="Лиги">
            <div class="miq-panel">
                <div
                    class="miq-selected-chips px-3 pt-2.5"
                    role="list"
                    aria-label="Выбранные турниры"
                >
                    <span
                        v-for="chip in selectedChips"
                        :key="chip.id"
                        class="miq-chip"
                        role="listitem"
                    >
                        <span>{{ chip.label }}</span>
                        <button
                            v-if="chip.removable"
                            type="button"
                            class="miq-chip-remove"
                            :aria-label="`Убрать ${chip.label}`"
                            @click="removeLeague(chip.id)"
                        >
                            ×
                        </button>
                    </span>
                </div>

                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Турниры</h2>
                    <p
                        v-if="soonHint"
                        class="animate-miq-fade text-xs font-medium text-miq-warn"
                        role="status"
                    >
                        {{ soonHint }}
                    </p>
                </div>

                <div
                    class="miq-league-picker animate-miq-stagger"
                    role="listbox"
                    aria-label="Переключатель лиг"
                    aria-multiselectable="true"
                >
                    <div class="miq-league-nav" role="tablist" aria-label="Страны">
                        <button
                            type="button"
                            role="option"
                            class="miq-league-all"
                            :class="{ 'is-active': isAllMode }"
                            :aria-selected="isAllMode"
                            @click="selectAll"
                        >
                            Все
                        </button>

                        <div class="miq-league-nav-sep" aria-hidden="true" />

                        <button
                            v-for="group in leaguesByCountry"
                            :key="group.country"
                            type="button"
                            role="tab"
                            class="miq-league-tab"
                            :class="{
                                'is-active': activeCountry === group.country,
                                'has-pick': group.selectedCount > 0,
                            }"
                            :aria-selected="activeCountry === group.country"
                            @click="setActiveCountry(group.country)"
                        >
                            <img
                                v-if="countryFlagUrl(group.country)"
                                class="miq-league-tab-flag"
                                :src="countryFlagUrl(group.country)"
                                :srcset="countryFlagSrcset(group.country)"
                                alt=""
                                width="20"
                                height="15"
                                loading="lazy"
                                decoding="async"
                                aria-hidden="true"
                            />
                            <span
                                v-else
                                class="miq-league-tab-flag miq-league-tab-flag--empty"
                                aria-hidden="true"
                            />
                            <span>{{ group.country }}</span>
                            <span
                                v-if="group.selectedCount > 0"
                                class="miq-league-tab-count"
                                aria-hidden="true"
                            >
                                {{ group.selectedCount }}
                            </span>
                            <span
                                v-else-if="group.hasLive"
                                class="miq-league-tab-dot"
                                aria-hidden="true"
                            />
                        </button>
                    </div>

                    <div
                        v-if="activeCountryGroup"
                        class="miq-league-pills"
                        role="group"
                        :aria-label="activeCountryGroup.country"
                    >
                        <button
                            v-for="league in activeCountryLeagues"
                            :key="league.id"
                            type="button"
                            role="option"
                            class="miq-league-pill"
                            :class="{
                                'is-active': isLeagueSelected(league.id) && league.status === 'live',
                                'is-soon': league.status === 'soon',
                            }"
                            :aria-selected="isLeagueSelected(league.id)"
                            :aria-disabled="league.status === 'soon'"
                            :title="league.name"
                            @click="selectLeague(league)"
                        >
                            <span class="truncate">{{ league.short || league.name }}</span>
                            <span
                                v-if="league.status === 'soon'"
                                class="miq-league-pill-soon"
                            >
                                скоро
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <template v-if="hasLiveLeagues">
            <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.65fr)_minmax(17rem,1fr)] lg:items-start">
                <!-- Left: matches -->
                <div class="min-w-0">
                    <section aria-label="Дата матчей" class="relative">
                        <div
                            ref="calendarRoot"
                            class="inline-flex items-stretch rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                            role="tablist"
                        >
                            <button
                                v-for="p in periods"
                                :key="p.id"
                                type="button"
                                role="tab"
                                class="miq-period"
                                :class="{ 'is-active': period === p.id && !isCustomDate }"
                                :aria-selected="period === p.id && !isCustomDate"
                                @click="selectPeriod(p.id)"
                            >
                                {{ p.label }}
                            </button>
                            <button
                                type="button"
                                class="miq-period miq-period-cal"
                                :class="{ 'is-active': isCustomDate || calendarOpen }"
                                :aria-expanded="calendarOpen"
                                aria-haspopup="dialog"
                                :aria-label="isCustomDate
                                    ? `Календарь, выбрано ${selectedDateKey}`
                                    : 'Открыть календарь'"
                                :title="isCustomDate ? selectedDateKey : 'Календарь'"
                                @click="toggleCalendar"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <rect x="3" y="4" width="18" height="18" rx="2" />
                                    <path d="M16 2v4M8 2v4M3 10h18" />
                                </svg>
                            </button>
                        </div>

                        <div
                            v-if="calendarOpen"
                            class="miq-cal"
                            role="dialog"
                            aria-label="Выбор даты"
                        >
                            <div class="miq-cal-head">
                                <button
                                    type="button"
                                    class="miq-cal-nav"
                                    aria-label="Предыдущий месяц"
                                    @click="shiftCalendarMonth(-1)"
                                >
                                    ‹
                                </button>
                                <p class="miq-cal-title">{{ calendarMonthLabel }}</p>
                                <button
                                    type="button"
                                    class="miq-cal-nav"
                                    aria-label="Следующий месяц"
                                    @click="shiftCalendarMonth(1)"
                                >
                                    ›
                                </button>
                            </div>
                            <div class="miq-cal-weekdays">
                                <span v-for="wd in WEEKDAYS" :key="wd">{{ wd }}</span>
                            </div>
                            <div class="miq-cal-grid">
                                <template v-for="cell in calendarDays" :key="cell.key">
                                    <span v-if="cell.empty" class="miq-cal-day is-empty" />
                                    <button
                                        v-else
                                        type="button"
                                        class="miq-cal-day"
                                        :class="{
                                            'is-today': cell.isToday,
                                            'is-selected': cell.isSelected,
                                        }"
                                        @click="selectCalendarDay(cell)"
                                    >
                                        {{ cell.day }}
                                    </button>
                                </template>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4" aria-label="Матчи" :key="listKey">
                        <div class="miq-panel">
                            <div class="miq-panel-head">
                                <h2 class="miq-panel-title">Матчи</h2>
                                <p class="truncate text-xs font-medium text-miq-ink-soft">
                                    {{ leagueHint }}
                                </p>
                            </div>

                            <div
                                v-if="filteredMatches.length === 0"
                                class="animate-miq-fade px-4 py-10 text-center"
                            >
                                <p class="font-medium text-miq-ink-soft">
                                    На эту дату матчей нет
                                </p>
                                <p class="mt-1 text-sm text-miq-muted">
                                    Попробуйте другой день выше.
                                </p>
                            </div>

                            <div v-else class="animate-miq-stagger divide-y divide-miq-line/60">
                                <div
                                    v-for="group in matchesByLeague"
                                    :key="group.key"
                                >
                                    <p
                                        class="border-b border-miq-line/60 bg-[color-mix(in_srgb,var(--miq-lime)_22%,white)] px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-miq-ink-soft"
                                    >
                                        {{ group.label }}
                                    </p>
                                    <ul>
                                        <li v-for="match in group.matches" :key="match.id">
                                            <Link
                                                :href="route('matches.show', match.id)"
                                                class="miq-match-row px-3.5 py-3.5"
                                            >
                                                <div class="flex items-center gap-3">
                                                    <div class="w-12 shrink-0 text-center">
                                                        <p
                                                            v-if="match.status === 'live'"
                                                            class="text-xs font-bold uppercase tracking-wide text-miq-live"
                                                        >
                                                            Live
                                                        </p>
                                                        <p
                                                            v-else
                                                            class="text-sm font-semibold tabular-nums text-miq-ink-soft"
                                                        >
                                                            {{ formatKickoff(match.kickoff_at) }}
                                                        </p>
                                                        <p
                                                            class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-muted"
                                                        >
                                                            {{ statusLabel(match.status) }}
                                                        </p>
                                                    </div>

                                                    <div class="min-w-0 flex-1">
                                                        <p class="miq-display text-[15px] font-semibold leading-snug text-miq-ink sm:text-base">
                                                            {{ match.home }}
                                                            <span class="font-medium text-miq-muted">—</span>
                                                            {{ match.away }}
                                                        </p>
                                                        <p
                                                            v-if="topProb(match)"
                                                            class="mt-1.5 flex flex-wrap items-center gap-1.5"
                                                        >
                                                            <span class="miq-prob">
                                                                {{ topProb(match).key }}
                                                                {{ topProb(match).value }}%
                                                            </span>
                                                            <span class="text-[11px] text-miq-muted">
                                                                топ-исход · открыть события
                                                            </span>
                                                        </p>
                                                    </div>

                                                    <div class="shrink-0 text-right">
                                                        <p
                                                            v-if="scoreText(match)"
                                                            class="font-display text-lg font-bold tabular-nums text-miq-ink"
                                                        >
                                                            {{ scoreText(match) }}
                                                        </p>
                                                        <span
                                                            v-else
                                                            class="text-xs font-medium text-miq-muted"
                                                            aria-hidden="true"
                                                        >
                                                            →
                                                        </span>
                                                    </div>
                                                </div>
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Right: informative widgets (desktop stack / mobile tabs) -->
                <aside class="min-w-0 space-y-4" aria-label="Обзор лиги">
                    <div class="lg:hidden">
                        <div
                            class="flex gap-1 rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                            role="tablist"
                            aria-label="Виджеты"
                        >
                            <button
                                v-for="tab in mobileWidgetTabs"
                                :key="tab.id"
                                type="button"
                                role="tab"
                                class="miq-widget-tab"
                                :class="{ 'is-active': mobileWidget === tab.id }"
                                :aria-selected="mobileWidget === tab.id"
                                @click="mobileWidget = tab.id"
                            >
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>

                    <section
                        class="miq-panel"
                        :class="{ 'hidden lg:block': mobileWidget !== 'standings' }"
                        aria-label="Турнирная таблица"
                    >
                        <div class="miq-panel-head">
                            <h2 class="miq-panel-title">Таблица</h2>
                            <p class="truncate text-xs font-medium text-miq-ink-soft">
                                {{ widgetLeagueLabel }}
                            </p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[16rem] text-left text-sm">
                                <thead>
                                    <tr class="border-b border-miq-line/60 text-[10px] uppercase tracking-wider text-miq-muted">
                                        <th class="px-3 py-2 font-semibold">#</th>
                                        <th class="px-1 py-2 font-semibold">Команда</th>
                                        <th class="px-2 py-2 text-right font-semibold">И</th>
                                        <th class="px-2 py-2 text-right font-semibold">РМ</th>
                                        <th class="px-3 py-2 text-right font-semibold">О</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in widgets.standings"
                                        :key="row.pos"
                                        class="border-b border-miq-line/40 last:border-0"
                                    >
                                        <td class="px-3 py-2">
                                            <span
                                                class="miq-rank"
                                                :class="{ 'is-top': row.pos <= 3 }"
                                            >
                                                {{ row.pos }}
                                            </span>
                                        </td>
                                        <td class="truncate px-1 py-2 font-medium text-miq-ink">
                                            {{ row.team }}
                                        </td>
                                        <td class="px-2 py-2 text-right tabular-nums text-miq-muted">
                                            {{ row.played }}
                                        </td>
                                        <td class="px-2 py-2 text-right tabular-nums text-miq-muted">
                                            {{ gdText(row.gd) }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-miq-ink">
                                            {{ row.pts }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section
                        class="miq-panel"
                        :class="{ 'hidden lg:block': mobileWidget !== 'scorers' }"
                        aria-label="Лучшие бомбардиры"
                    >
                        <div class="miq-panel-head">
                            <h2 class="miq-panel-title">Бомбардиры</h2>
                            <p class="text-xs text-miq-muted">Лига</p>
                        </div>
                        <ul class="divide-y divide-miq-line/50">
                            <li
                                v-for="row in widgets.scorers"
                                :key="row.pos"
                                class="flex items-center gap-2.5 px-3.5 py-2.5"
                            >
                                <span
                                    class="miq-rank"
                                    :class="{ 'is-top': row.pos <= 3 }"
                                >
                                    {{ row.pos }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-miq-ink">
                                        {{ row.player }}
                                    </p>
                                    <p class="truncate text-[11px] text-miq-muted">
                                        {{ row.team }}
                                    </p>
                                </div>
                                <p class="text-sm font-bold tabular-nums text-miq-pitch-deep">
                                    {{ row.goals }}
                                </p>
                            </li>
                        </ul>
                    </section>

                    <section
                        class="miq-panel"
                        :class="{ 'hidden lg:block': mobileWidget !== 'hot' }"
                        aria-label="Скорострелы"
                    >
                        <div class="miq-panel-head">
                            <h2 class="miq-panel-title">Скорострелы</h2>
                            <p class="text-xs text-miq-muted">Год</p>
                        </div>
                        <ul class="divide-y divide-miq-line/50">
                            <li
                                v-for="row in widgets.hotScorers"
                                :key="row.pos"
                                class="flex items-center gap-2.5 px-3.5 py-2.5"
                            >
                                <span
                                    class="miq-rank"
                                    :class="{ 'is-top': row.pos <= 3 }"
                                >
                                    {{ row.pos }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-miq-ink">
                                        {{ row.player }}
                                    </p>
                                    <p class="truncate text-[11px] text-miq-muted">
                                        {{ row.team }} · {{ row.league }}
                                    </p>
                                </div>
                                <p class="text-sm font-bold tabular-nums text-miq-pitch-deep">
                                    {{ row.goals }}
                                </p>
                            </li>
                        </ul>
                    </section>

                    <section
                        class="miq-panel"
                        :class="{ 'hidden lg:block': mobileWidget !== 'news' }"
                        aria-label="Новости"
                    >
                        <div class="miq-panel-head">
                            <h2 class="miq-panel-title">Новости</h2>
                            <p class="text-xs text-miq-muted">Промо</p>
                        </div>
                        <ul class="divide-y divide-miq-line/50">
                            <li
                                v-for="item in widgets.news"
                                :key="item.id"
                                class="px-3.5 py-3"
                            >
                                <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wide text-miq-muted">
                                    <span class="rounded bg-miq-mist-deep/80 px-1.5 py-0.5 text-miq-pitch-deep">
                                        {{ item.tag }}
                                    </span>
                                    <span>{{ item.ago }}</span>
                                </div>
                                <p class="mt-1.5 text-sm font-semibold leading-snug text-miq-ink">
                                    {{ item.title }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </template>

        <section
            v-else
            class="mt-10 border-y border-dashed border-miq-line py-12 text-center"
        >
            <p class="miq-display text-xl font-semibold text-miq-ink">
                Матчи скоро появятся
            </p>
            <p class="mt-2 text-sm text-miq-muted">
                Пока нет ни одной активной лиги.
            </p>
        </section>
    </AppLayout>
</template>
