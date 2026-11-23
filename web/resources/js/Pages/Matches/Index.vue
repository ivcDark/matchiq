<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    leagues: { type: Array, default: () => [] },
    matches: { type: Array, default: () => [] },
    selectedLeague: { type: String, default: 'all' },
    period: { type: String, default: 'upcoming' },
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
    { id: 'today', label: 'Сегодня' },
    { id: 'upcoming', label: 'Ближайшие' },
    { id: 'finished', label: 'Завершённые' },
];

const mobileWidgetTabs = [
    { id: 'standings', label: 'Таблица' },
    { id: 'scorers', label: 'Бомбардиры' },
    { id: 'hot', label: 'Скорострелы' },
    { id: 'news', label: 'Новости' },
];

/** Сворачивать страны, если лиг много (не плоская бесконечная сетка). */
const COLLAPSE_THRESHOLD = 6;

const soonHint = ref(null);
const mobileWidget = ref('standings');
const openCountries = ref({});
let soonTimer = null;

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

const useCollapsibleCountries = computed(
    () => props.leagues.length >= COLLAPSE_THRESHOLD,
);

const leaguesByCountry = computed(() => {
    const order = [];
    const map = new Map();

    for (const league of props.leagues) {
        const country = league.country || 'Другие';
        if (!map.has(country)) {
            const group = {
                country,
                leagues: [],
                hasLive: false,
            };
            map.set(country, group);
            order.push(group);
        }
        const group = map.get(country);
        group.leagues.push(league);
        if (league.status === 'live') {
            group.hasLive = true;
        }
    }

    return order;
});

const activeLeague = computed(() => {
    if (props.selectedLeague === 'all') {
        return null;
    }
    return props.leagues.find((l) => l.id === props.selectedLeague) ?? null;
});

const leagueHint = computed(() => {
    if (props.selectedLeague === 'all') {
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
    if (!activeLeague.value) {
        return null;
    }
    return `${activeLeague.value.country} · ${activeLeague.value.name}`;
});

const filteredMatches = computed(() => {
    return props.matches.filter((m) => {
        if (props.selectedLeague === 'all') {
            if (!liveLeagueIds.value.has(m.league_id)) {
                return false;
            }
        } else if (m.league_id !== props.selectedLeague) {
            return false;
        }

        if (props.period === 'today') {
            return m.period_bucket === 'today';
        }
        if (props.period === 'finished') {
            return m.period_bucket === 'finished' || m.status === 'finished';
        }
        return m.period_bucket === 'upcoming' || m.period_bucket === 'today';
    });
});

const matchesByDate = computed(() => {
    const groups = [];
    const map = new Map();

    for (const match of filteredMatches.value) {
        const key = dateGroupKey(match.kickoff_at);
        if (!map.has(key)) {
            const group = { key, label: dateGroupLabel(match.kickoff_at), matches: [] };
            map.set(key, group);
            groups.push(group);
        }
        map.get(key).matches.push(match);
    }

    return groups;
});

const hasLiveLeagues = computed(() => liveLeagueIds.value.size > 0);

const widgetLeagueLabel = computed(
    () => props.widgets?.league?.label ?? leagueHint.value ?? 'Лига',
);

const showLeagueInRow = computed(() => props.selectedLeague === 'all');

function initOpenCountries() {
    const next = {};
    for (const group of leaguesByCountry.value) {
        const selectedHere = group.leagues.some((l) => l.id === props.selectedLeague);
        // Live-страны и страна выбранной лиги открыты; «скоро»-только — свёрнуты при коллапсе.
        next[group.country] = !useCollapsibleCountries.value
            || group.hasLive
            || selectedHere;
    }
    openCountries.value = next;
}

watch(
    () => [props.leagues, props.selectedLeague, useCollapsibleCountries.value],
    () => initOpenCountries(),
    { immediate: true },
);

function toggleCountry(country) {
    if (!useCollapsibleCountries.value) {
        return;
    }
    openCountries.value = {
        ...openCountries.value,
        [country]: !openCountries.value[country],
    };
}

function isCountryOpen(country) {
    if (!useCollapsibleCountries.value) {
        return true;
    }
    return Boolean(openCountries.value[country]);
}

function selectAll() {
    if (props.selectedLeague === 'all') {
        return;
    }
    navigate({ league: 'all', period: props.period });
}

function selectLeague(league) {
    if (league.status !== 'live') {
        showSoonHint();
        return;
    }
    if (league.id === props.selectedLeague) {
        return;
    }
    navigate({ league: league.id, period: props.period });
}

function selectPeriod(periodId) {
    if (periodId === props.period) {
        return;
    }
    navigate({ league: props.selectedLeague, period: periodId });
}

function navigate({ league, period }) {
    router.get(
        route('matches.index'),
        { league, period },
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

function formatKickoff(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return new Intl.DateTimeFormat('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

function dateGroupKey(iso) {
    if (!iso) return 'unknown';
    const d = new Date(iso);
    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

function dateGroupLabel(iso) {
    if (!iso) return 'Без даты';
    const d = new Date(iso);
    const today = new Date();
    const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const startMatch = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const diffDays = Math.round((startMatch - startToday) / 86400000);

    if (diffDays === 0) return 'Сегодня';
    if (diffDays === 1) return 'Завтра';
    if (diffDays === -1) return 'Вчера';

    return new Intl.DateTimeFormat('ru-RU', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(d);
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

function matchLeagueShort(match) {
    const league = leagueById.value.get(match.league_id);
    return league?.short || league?.name || match.league_id;
}

const listKey = computed(
    () => `${props.selectedLeague}-${props.period}`,
);
</script>

<template>
    <Head title="Матчи" />

    <AppLayout>
        <!-- Tournament / league menu ABOVE matches -->
        <section class="animate-miq-fade-up" style="animation-delay: 60ms" aria-label="Лиги">
            <div class="miq-panel">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Турниры</h2>
                    <p
                        v-if="soonHint"
                        class="animate-miq-fade text-xs font-medium text-miq-warn"
                        role="status"
                    >
                        {{ soonHint }}
                    </p>
                    <p v-else class="text-xs text-miq-muted">
                        Все · live · «скоро»
                    </p>
                </div>

                <div
                    class="miq-league-menu animate-miq-stagger p-3"
                    role="listbox"
                    aria-label="Переключатель лиг"
                >
                    <button
                        type="button"
                        role="option"
                        class="miq-league-all"
                        :class="{ 'is-active': selectedLeague === 'all' }"
                        :aria-selected="selectedLeague === 'all'"
                        @click="selectAll"
                    >
                        <span class="min-w-0">
                            <span class="block font-semibold text-miq-ink">Все</span>
                            <span class="mt-0.5 block text-[11px] font-medium text-miq-muted">
                                Активные лиги
                            </span>
                        </span>
                        <span
                            v-if="selectedLeague === 'all'"
                            class="h-1.5 w-1.5 shrink-0 rounded-full bg-miq-pitch"
                            aria-hidden="true"
                        />
                    </button>

                    <div
                        v-for="group in leaguesByCountry"
                        :key="group.country"
                        class="miq-league-country"
                    >
                        <button
                            v-if="useCollapsibleCountries"
                            type="button"
                            class="miq-league-country-toggle"
                            :aria-expanded="isCountryOpen(group.country)"
                            @click="toggleCountry(group.country)"
                        >
                            <span class="text-[11px] font-semibold uppercase tracking-[0.14em]">
                                {{ group.country }}
                            </span>
                            <span class="flex items-center gap-2">
                                <span
                                    v-if="group.hasLive"
                                    class="rounded px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-pitch-deep"
                                    style="background: color-mix(in srgb, var(--miq-pitch) 12%, white)"
                                >
                                    Live
                                </span>
                                <span
                                    class="text-miq-muted transition"
                                    :class="{ 'rotate-180': isCountryOpen(group.country) }"
                                    aria-hidden="true"
                                >
                                    ▾
                                </span>
                            </span>
                        </button>
                        <p v-else class="miq-league-country-head">
                            {{ group.country }}
                        </p>

                        <div
                            v-show="isCountryOpen(group.country)"
                            class="miq-league-country-grid"
                            role="group"
                            :aria-label="group.country"
                        >
                            <button
                                v-for="league in group.leagues"
                                :key="league.id"
                                type="button"
                                role="option"
                                class="miq-league-item"
                                :class="{
                                    'is-active': league.id === selectedLeague && league.status === 'live',
                                    'is-soon': league.status === 'soon',
                                }"
                                :aria-selected="league.id === selectedLeague"
                                :aria-disabled="league.status === 'soon'"
                                @click="selectLeague(league)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-miq-ink">
                                        {{ league.short || league.name }}
                                    </span>
                                    <span class="mt-0.5 block truncate text-[11px] font-medium text-miq-muted">
                                        {{ league.name }}
                                    </span>
                                </span>
                                <span
                                    v-if="league.status === 'soon'"
                                    class="shrink-0 rounded px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-muted"
                                >
                                    Скоро
                                </span>
                                <span
                                    v-else-if="league.id === selectedLeague"
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-miq-pitch"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <template v-if="hasLiveLeagues">
            <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.65fr)_minmax(17rem,1fr)] lg:items-start">
                <!-- Left: matches -->
                <div class="min-w-0">
                    <section aria-label="Период">
                        <div
                            class="inline-flex rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                            role="tablist"
                        >
                            <button
                                v-for="p in periods"
                                :key="p.id"
                                type="button"
                                role="tab"
                                class="miq-period"
                                :class="{ 'is-active': period === p.id }"
                                :aria-selected="period === p.id"
                                @click="selectPeriod(p.id)"
                            >
                                {{ p.label }}
                            </button>
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
                                    На этот период матчей нет
                                </p>
                                <p class="mt-1 text-sm text-miq-muted">
                                    Попробуйте другой период выше.
                                </p>
                            </div>

                            <div v-else class="animate-miq-stagger divide-y divide-miq-line/60">
                                <div
                                    v-for="group in matchesByDate"
                                    :key="group.key"
                                >
                                    <p
                                        class="border-b border-miq-line/50 bg-miq-mist/60 px-3.5 py-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-miq-muted"
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
                                                        <p
                                                            v-if="showLeagueInRow"
                                                            class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-muted"
                                                        >
                                                            {{ matchLeagueShort(match) }}
                                                        </p>
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
