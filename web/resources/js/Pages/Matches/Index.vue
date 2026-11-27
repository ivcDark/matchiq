<script setup>
import MatchOfDay from '@/Components/MatchOfDay.vue';
import MatchProbTiles from '@/Components/MatchProbTiles.vue';
import ModelTrustStrip from '@/Components/ModelTrustStrip.vue';
import TeamLogo from '@/Components/TeamLogo.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { bestMarkets, firstSentence, hasResultProbs, maxResultProb } from '@/lib/matchProbs';
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
        }),
    },
    /** Точность модели для полосы доверия; `is_placeholder` — значение-заглушка, пока нет реальной метрики. */
    modelStats: {
        type: Object,
        default: () => ({ hit_rate: null, is_placeholder: false }),
    },
});

/** Сколько лиг выводим чипами в sticky-строке; остальные — в поповере «Все лиги». */
const TOP_LEAGUE_CHIPS = 7;

const periods = [
    { id: 'yesterday', label: 'Вчера' },
    { id: 'today', label: 'Сегодня' },
    { id: 'tomorrow', label: 'Завтра' },
];

const perNinety = (n) =>
    new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

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

/** Код флага: сначала таблица (Англия → gb-eng), иначе ISO-код страны, пришедший с бэка. */
function countryFlagCode(country) {
    if (COUNTRY_FLAG_CODES[country]) return COUNTRY_FLAG_CODES[country];
    const iso = props.leagues.find((l) => l.country === country)?.country_code;
    return iso ? iso.toLowerCase() : null;
}

function countryFlagUrl(country) {
    const code = countryFlagCode(country);
    return code ? `https://flagcdn.com/w20/${code}.png` : null;
}

function countryFlagSrcset(country) {
    const code = countryFlagCode(country);
    return code ? `https://flagcdn.com/w40/${code}.png 2x` : null;
}

const railTabs = [
    { id: 'standings', label: 'Таблица' },
    { id: 'scorers', label: 'Бомбардиры' },
    { id: 'previews', label: 'Превью', mobileOnly: true },
];

const soonHint = ref(null);
const railTab = ref('standings');
const leaguesOpen = ref(false);
const filterBarRoot = ref(null);
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

/** Топ-лиги чипами: сначала live по sort_order, затем «скоро»; не больше TOP_LEAGUE_CHIPS. */
const topLeagues = computed(() => {
    const rank = (l) => (l.status === 'live' ? 0 : 1);
    return [...props.leagues]
        .sort((a, b) => rank(a) - rank(b) || (a.sort_order ?? 0) - (b.sort_order ?? 0))
        .slice(0, TOP_LEAGUE_CHIPS);
});

/** Выбранные live-лиги, не попавшие в топ-чипы: показываем рядом с × (снять). */
const extraSelectedLeagues = computed(() => {
    const top = new Set(topLeagues.value.map((l) => l.id));
    return selectedLeagueIds.value
        .filter((id) => !top.has(id))
        .map((id) => leagueById.value.get(id))
        .filter(Boolean);
});

const hiddenLeaguesCount = computed(
    () => Math.max(0, props.leagues.length - topLeagues.value.length),
);

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
                tournamentId: league?.tournament_id ?? null,
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

function toggleLeagues() {
    leaguesOpen.value = !leaguesOpen.value;
    if (leaguesOpen.value) {
        calendarOpen.value = false;
        syncActiveCountry();
    }
}

function selectAll() {
    if (isAllMode.value) {
        return;
    }
    leaguesOpen.value = false;
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
        leaguesOpen.value = false;
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
    if (calendarOpen.value && calendarRoot.value && !calendarRoot.value.contains(event.target)) {
        calendarOpen.value = false;
    }
    if (leaguesOpen.value && filterBarRoot.value && !filterBarRoot.value.contains(event.target)) {
        leaguesOpen.value = false;
    }
}

function onDocumentKeydown(event) {
    if (event.key === 'Escape') {
        leaguesOpen.value = false;
        calendarOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    document.addEventListener('keydown', onDocumentKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    document.removeEventListener('keydown', onDocumentKeydown);
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
    if (status === 'postponed') return 'Перенесён';
    if (status === 'cancelled') return 'Отменён';
    if (status === 'abandoned') return 'Прерван';
    return 'Ждём';
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

/** Нормализация формы: В/Н/П (legacy W/D/L тоже). */
function normalizeFormResult(result) {
    const s = String(result || '').trim().toUpperCase();
    if (s === 'W' || s === 'В') return 'В';
    if (s === 'D' || s === 'Н') return 'Н';
    if (s === 'L' || s === 'П') return 'П';
    return null;
}

/** Последние результаты команды: В / Н / П (демо-массивы с бэка). */
function formResults(match, side) {
    const key = side === 'away' ? 'form_away' : 'form_home';
    const raw = match?.[key];
    if (!Array.isArray(raw) || raw.length === 0) return [];
    return raw.map(normalizeFormResult).filter(Boolean);
}

function formStickClass(result) {
    const n = normalizeFormResult(result);
    if (n === 'В') return 'is-w';
    if (n === 'Н') return 'is-d';
    return 'is-l';
}

function formAriaLabel(results, side) {
    if (!results.length) return '';
    const sideLabel = side === 'away' ? 'гости' : 'хозяева';
    const labels = results.map((r) => {
        const n = normalizeFormResult(r);
        if (n === 'В') return 'победа';
        if (n === 'Н') return 'ничья';
        return 'поражение';
    });
    return `${sideLabel}: ${labels.join(', ')}`;
}

/** Ссылка на матч с league для возврата на тот же фильтр. */
function matchShowHref(match) {
    const base = route('matches.show', match.id);
    const leagueParam = isAllMode.value
        ? 'all'
        : selectedLeagueIds.value.join(',');
    if (!leagueParam) {
        return base;
    }
    return `${base}?league=${encodeURIComponent(leagueParam)}`;
}

function gdText(gd) {
    if (gd > 0) return `+${gd}`;
    return String(gd);
}

/** Строка команды в матче: логотип, имя, форма палочками, голы. */
function teamLines(match) {
    return [
        { side: 'home', name: match.home, logo: match.home_logo, goals: match.score_home, form: formResults(match, 'home') },
        { side: 'away', name: match.away, logo: match.away_logo, goals: match.score_away, form: formResults(match, 'away') },
    ];
}

const upcomingMatches = computed(() => {
    const list = [];
    for (const group of matchesByLeague.value) {
        for (const match of group.matches) {
            if (match.status === 'finished') continue;
            list.push({ match, leagueLabel: group.label });
        }
    }
    return list;
});

/** «Матч дня»: максимальный max(П1, Х, П2) среди несыгранных; без прогнозов — ближайший по времени. */
const featured = computed(() => {
    const list = upcomingMatches.value;
    if (list.length === 0) return null;

    const withProbs = list.filter((x) => hasResultProbs(x.match));
    if (withProbs.length > 0) {
        return withProbs.reduce((best, x) => (maxResultProb(x.match) > maxResultProb(best.match) ? x : best));
    }

    return list.reduce((best, x) => (
        kickoffSortValue(x.match.kickoff_at) < kickoffSortValue(best.match.kickoff_at) ? x : best
    ));
});

/** «Превью матчей» в рейле: заголовок — первое предложение AI-превью, иначе вопрос-заглушка. */
const previewItems = computed(() => {
    const items = upcomingMatches.value.map(({ match, leagueLabel }) => {
        const text = typeof match.preview === 'string' ? match.preview.trim() : '';
        return {
            id: match.id,
            href: matchShowHref(match),
            title: text ? firstSentence(text) : `${match.home} — ${match.away}: кто возьмёт верх?`,
            meta: `${leagueLabel} · ${formatKickoff(match.kickoff_at)}`,
            hasPreview: text !== '',
        };
    });
    items.sort((a, b) => Number(b.hasPreview) - Number(a.hasPreview));
    return items.slice(0, 5);
});

const listKey = computed(
    () => `${props.selectedLeagues.join(',')}-${selectedDateKey.value}`,
);
</script>

<template>
    <Head title="Матчи" />

    <AppLayout>
        <section
            id="tournaments"
            ref="filterBarRoot"
            class="miq-filterbar"
            aria-label="Лиги"
        >
            <div class="miq-filterbar-inner">
                <div
                    class="miq-filterbar-row"
                    role="listbox"
                    aria-label="Переключатель лиг"
                    aria-multiselectable="true"
                >
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
                        v-for="league in topLeagues"
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
                        <img
                            v-if="countryFlagUrl(league.country)"
                            class="miq-league-tab-flag"
                            :src="countryFlagUrl(league.country)"
                            :srcset="countryFlagSrcset(league.country)"
                            alt=""
                            width="18"
                            height="14"
                            loading="lazy"
                            decoding="async"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ league.short || league.name }}</span>
                        <span v-if="league.status === 'soon'" class="miq-league-pill-soon">скоро</span>
                    </button>

                    <span
                        v-for="league in extraSelectedLeagues"
                        :key="`extra-${league.id}`"
                        class="miq-chip"
                        :title="league.name"
                    >
                        <span>{{ league.short || league.name }}</span>
                        <button
                            type="button"
                            class="miq-chip-remove"
                            :aria-label="`Убрать ${league.short || league.name}`"
                            @click="removeLeague(league.id)"
                        >
                            ×
                        </button>
                    </span>
                </div>

                <button
                    type="button"
                    class="miq-filterbar-more"
                    :class="{ 'is-open': leaguesOpen }"
                    aria-haspopup="dialog"
                    :aria-expanded="leaguesOpen"
                    @click="toggleLeagues"
                >
                    <span>Все лиги</span>
                    <span v-if="hiddenLeaguesCount > 0" class="miq-league-tab-count" aria-hidden="true">
                        +{{ hiddenLeaguesCount }}
                    </span>
                    <svg
                        class="miq-trust-chevron"
                        :class="{ 'is-open': leaguesOpen }"
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M5 7.5l5 5 5-5" />
                    </svg>
                </button>
            </div>

            <p
                v-if="soonHint"
                class="miq-filterbar-toast animate-miq-fade"
                role="status"
            >
                {{ soonHint }}
            </p>

            <div
                v-if="leaguesOpen"
                class="miq-filterpop animate-miq-fade"
                role="dialog"
                aria-label="Все лиги"
            >
                <div class="miq-league-nav" role="tablist" aria-label="Страны">
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
                    class="miq-league-pills mt-2"
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
        </section>

        <template v-if="hasLiveLeagues">
            <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1.65fr)_minmax(17rem,1fr)] lg:items-start">
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

                    <MatchOfDay
                        v-if="featured"
                        :key="`motd-${featured.match.id}`"
                        class="mt-3"
                        :match="featured.match"
                        :href="matchShowHref(featured.match)"
                        :league-label="featured.leagueLabel"
                        :time="formatKickoff(featured.match.kickoff_at)"
                    />

                    <section class="mt-3" aria-label="Матчи" :key="listKey">
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
                                    <Link
                                        v-if="group.tournamentId"
                                        :href="route('tournaments.show', group.tournamentId)"
                                        class="miq-league-head miq-league-head-link"
                                        :title="`${group.label} — страница турнира`"
                                    >
                                        <span>{{ group.label }}</span>
                                        <svg
                                            class="miq-link-chevron"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M7.5 5l5 5-5 5" />
                                        </svg>
                                    </Link>
                                    <p v-else class="miq-league-head">
                                        {{ group.label }}
                                    </p>
                                    <ul>
                                        <li v-for="match in group.matches" :key="match.id">
                                            <Link
                                                :href="matchShowHref(match)"
                                                class="miq-match-row px-3.5 py-3"
                                            >
                                                <div class="miq-hm">
                                                    <div class="miq-hm-time">
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
                                                        <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-muted">
                                                            {{ statusLabel(match.status) }}
                                                        </p>
                                                    </div>

                                                    <div class="miq-hm-teams">
                                                        <div
                                                            v-for="t in teamLines(match)"
                                                            :key="t.side"
                                                            class="miq-hm-team"
                                                        >
                                                            <TeamLogo :src="t.logo" :name="t.name" :size="22" />
                                                            <span class="miq-team-name miq-display" :title="t.name">{{ t.name }}</span>
                                                            <span
                                                                v-if="t.form.length"
                                                                class="miq-form-sticks"
                                                                role="img"
                                                                :aria-label="formAriaLabel(t.form, t.side)"
                                                            >
                                                                <span
                                                                    v-for="(result, idx) in t.form"
                                                                    :key="`${match.id}-${t.side}-${idx}`"
                                                                    class="miq-form-stick"
                                                                    :class="formStickClass(result)"
                                                                    :title="result"
                                                                ></span>
                                                            </span>
                                                            <span
                                                                class="miq-hm-goals"
                                                                :class="{ 'is-live': match.status === 'live' }"
                                                                :aria-label="`Счёт: ${t.goals ?? 'нет'}`"
                                                            >{{ t.goals ?? '' }}</span>
                                                        </div>
                                                    </div>

                                                    <div v-if="match.status !== 'finished'" class="miq-hm-bets">
                                                        <MatchProbTiles :match="match" />
                                                        <span
                                                            v-if="bestMarkets(match, 1).length"
                                                            class="miq-market-chip"
                                                            :title="bestMarkets(match, 1)[0].market"
                                                        >
                                                            <span class="miq-market-label">{{ bestMarkets(match, 1)[0].label }}</span>
                                                            <span class="miq-market-dot" aria-hidden="true">·</span>
                                                            <span class="miq-odds-val">{{ bestMarkets(match, 1)[0].prob }}%</span>
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

                <aside class="min-w-0 space-y-4" aria-label="Обзор лиги">
                    <div class="lg:hidden">
                        <div
                            class="flex gap-1 rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                            role="tablist"
                            aria-label="Блоки справа"
                        >
                            <button
                                v-for="tab in railTabs"
                                :key="tab.id"
                                type="button"
                                role="tab"
                                class="miq-widget-tab"
                                :class="{ 'is-active': railTab === tab.id }"
                                :aria-selected="railTab === tab.id"
                                @click="railTab = tab.id"
                            >
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>

                    <section
                        class="miq-panel"
                        :class="{ 'hidden lg:block': railTab === 'previews' }"
                        aria-label="Таблица и бомбардиры"
                    >
                        <div class="miq-panel-head miq-rail-head">
                            <div class="miq-rail-tabs hidden lg:flex" role="tablist" aria-label="Таблица или бомбардиры">
                                <button
                                    v-for="tab in railTabs.filter((t) => !t.mobileOnly)"
                                    :key="tab.id"
                                    type="button"
                                    role="tab"
                                    class="miq-rail-tab"
                                    :class="{ 'is-active': railTab === tab.id }"
                                    :aria-selected="railTab === tab.id"
                                    @click="railTab = tab.id"
                                >
                                    {{ tab.label }}
                                </button>
                            </div>
                            <h2 class="miq-panel-title lg:hidden">
                                {{ railTab === 'scorers' ? 'Бомбардиры' : 'Таблица' }}
                            </h2>
                            <Link
                                v-if="widgets?.league?.tournament_id"
                                :href="route('tournaments.show', widgets.league.tournament_id)"
                                class="miq-panel-link"
                                :title="`${widgetLeagueLabel} — страница турнира`"
                            >
                                <span class="truncate">{{ widgetLeagueLabel }}</span>
                                <svg
                                    class="miq-link-chevron"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M7.5 5l5 5-5 5" />
                                </svg>
                            </Link>
                            <p v-else class="truncate text-xs font-medium text-miq-ink-soft">
                                {{ widgetLeagueLabel }}
                            </p>
                        </div>

                        <div v-if="railTab !== 'scorers'" class="overflow-x-auto">
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
                                    <tr v-if="!widgets.standings?.length">
                                        <td colspan="5" class="px-3 py-4 text-center text-sm text-miq-muted">
                                            Таблица появится после первых сыгранных матчей
                                        </td>
                                    </tr>
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
                                            <Link
                                                v-if="row.team_id"
                                                :href="route('clubs.show', row.team_id)"
                                                class="miq-club-link block truncate"
                                                :title="`${row.team} — страница клуба`"
                                            >{{ row.team }}</Link>
                                            <span v-else class="block truncate">{{ row.team }}</span>
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

                        <ul v-else class="divide-y divide-miq-line/50">
                            <li v-if="!widgets.scorers?.length" class="px-3.5 py-4 text-sm text-miq-muted">
                                Данные появятся после загрузки событий матчей
                            </li>
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
                        :class="{ 'hidden lg:block': railTab !== 'previews' }"
                        aria-label="Превью матчей"
                    >
                        <div class="miq-panel-head">
                            <h2 class="miq-panel-title">Превью матчей</h2>
                            <p class="text-xs text-miq-muted">AI</p>
                        </div>
                        <ul class="divide-y divide-miq-line/50">
                            <li v-if="!previewItems.length" class="px-3.5 py-4 text-sm text-miq-muted">
                                Нет предстоящих матчей для превью
                            </li>
                            <li v-for="item in previewItems" :key="item.id">
                                <Link :href="item.href" class="miq-preview-item">
                                    <p class="text-sm font-semibold leading-snug text-miq-ink">
                                        {{ item.title }}
                                    </p>
                                    <p class="mt-1 flex items-center gap-1.5 text-[11px] text-miq-muted">
                                        <span
                                            class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                            :class="item.hasPreview
                                                ? 'bg-miq-mist-deep/80 text-miq-pitch-deep'
                                                : 'bg-miq-mist-deep/50 text-miq-muted'"
                                        >{{ item.hasPreview ? 'AI' : 'скоро' }}</span>
                                        <span class="truncate">{{ item.meta }}</span>
                                    </p>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <details v-if="widgets.hotScorers?.length" class="miq-panel miq-rail-details">
                        <summary class="miq-panel-head cursor-pointer select-none">
                            <h2 class="miq-panel-title">Скорострелы</h2>
                            <p class="text-xs text-miq-muted">топ мира · Гол/90 · год</p>
                        </summary>
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
                                <div class="text-right">
                                    <p class="text-sm font-bold tabular-nums text-miq-pitch-deep">
                                        {{ perNinety(row.goals_per_90) }}
                                    </p>
                                    <p class="text-[11px] tabular-nums text-miq-muted">
                                        {{ row.goals }} г · {{ row.minutes }} мин
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </details>
                </aside>
            </div>

            <ModelTrustStrip
                class="mt-5"
                :hit-rate="modelStats?.hit_rate ?? null"
                :is-placeholder="Boolean(modelStats?.is_placeholder)"
            />
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
