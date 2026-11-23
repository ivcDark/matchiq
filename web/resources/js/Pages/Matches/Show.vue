<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    match: { type: Object, required: true },
    preview: { type: String, default: null },
    /** Рынки БК-вида: { id, name, popular_pct, outcomes: [{ id, label, prob, popular_pct, hit }] } */
    markets: { type: Array, default: () => [] },
    /** { match, first_half, second_half } — строки метрик с scale */
    comparison: {
        type: Object,
        default: () => ({ match: [], first_half: [], second_half: [] }),
    },
    /** { tournament, last5, last10 } → { home, away } */
    homeAway: {
        type: Object,
        default: () => ({ tournament: null, last5: null, last10: null }),
    },
    standings: { type: Array, default: () => [] },
    scorers: { type: Object, default: () => ({ home: [], away: [] }) },
    absences: { type: Object, default: () => ({ home: [], away: [] }) },
    goalMinutes: { type: Array, default: () => [] },
    /** 5 карточек формы: { id, title, matches: [{ date, home, away, score_home, score_away, result }] } */
    formBlocks: { type: Array, default: () => [] },
    /** Крупный 1X2 + correct score */
    forecast: {
        type: Object,
        default: () => ({
            home_pct: 0,
            draw_pct: 0,
            away_pct: 0,
            score_home: 0,
            score_away: 0,
            correct_scores: [],
        }),
    },
    /** Bullet-факты под AI-превью */
    insights: { type: Array, default: () => [] },
    /** Серии/тренды home|away */
    trends: { type: Object, default: () => ({ home: [], away: [] }) },
    /** Средние голы / HT-FT / тоталы по таймам */
    goalProfile: {
        type: Object,
        default: () => ({ averages: null, ht_ft: [], halves: [] }),
    },
    comments: { type: Array, default: () => [] },
    userPickEventId: { type: Number, default: null },
    returnLeague: { type: String, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const filters = [
    { id: 'best', label: 'Лучшие' },
    { id: 'worst', label: 'Худшие' },
    { id: 'popular', label: 'Популярные' },
];

const activeFilter = ref('best');
const localPickId = ref(props.userPickEventId);
const guestCtaOpen = ref(false);

/** b = шкалы + круги для % · c = все кругами */
const compareView = ref('b');
/** match | first_half | second_half */
const comparePeriod = ref('match');

const comparePeriods = [
    { id: 'match', label: 'Матч' },
    { id: 'first_half', label: '1-й тайм' },
    { id: 'second_half', label: '2-й тайм' },
];

const compareViews = [
    {
        id: 'b',
        label: 'Шкалы',
        title: 'Шкалы, проценты кругами',
        icon: 'bars',
    },
    {
        id: 'c',
        label: 'Круги',
        title: 'Все показатели кругами',
        icon: 'pie',
    },
];

/** tournament | last5 | last10 */
const homeAwaySample = ref('tournament');
const homeAwaySamples = [
    { id: 'tournament', label: 'В турнире' },
    { id: 'last5', label: '5 матчей' },
    { id: 'last10', label: '10 матчей' },
];

const activeHomeAway = computed(() => {
    const bag = props.homeAway || {};
    const sample = bag[homeAwaySample.value];
    if (sample?.home && sample?.away) return sample;
    if (bag.tournament?.home && bag.tournament?.away) return bag.tournament;
    // fallback на старую плоскую форму { home, away }
    if (bag.home && bag.away) return bag;
    return null;
});

const backHref = computed(() => {
    if (props.returnLeague) {
        return route('matches.index', { league: props.returnLeague });
    }
    if (props.match?.league_id) {
        return route('matches.index', { league: props.match.league_id });
    }
    return route('matches.index');
});

const pickClosed = computed(() => {
    const status = props.match?.status;
    return status === 'live' || status === 'finished';
});

function marketMaxProb(market) {
    const outcomes = market?.outcomes || [];
    if (!outcomes.length) return 0;
    return Math.max(...outcomes.map((o) => Number(o.prob) || 0));
}

function marketMinProb(market) {
    const outcomes = market?.outcomes || [];
    if (!outcomes.length) return 0;
    return Math.min(...outcomes.map((o) => Number(o.prob) || 0));
}

function marketPopular(market) {
    if (typeof market?.popular_pct === 'number') return market.popular_pct;
    const outcomes = market?.outcomes || [];
    if (!outcomes.length) return 0;
    return Math.max(...outcomes.map((o) => Number(o.popular_pct) || 0));
}

/** Сортировка рынков: лучшие = max p; худшие = min p; популярные = popular. */
const filteredMarkets = computed(() => {
    const list = [...(props.markets || [])];
    if (activeFilter.value === 'worst') {
        list.sort((a, b) => marketMinProb(a) - marketMinProb(b));
    } else if (activeFilter.value === 'popular') {
        list.sort((a, b) => marketPopular(b) - marketPopular(a));
    } else {
        list.sort((a, b) => marketMaxProb(b) - marketMaxProb(a));
    }
    return list;
});

/** Общий max шкалы голов: max(номинальный scale, все значения). */
const goalMax = computed(() => {
    let max = 0.01;
    for (const band of props.goalMinutes || []) {
        max = Math.max(
            max,
            Number(band.scale) || 0,
            Number(band.home) || 0,
            Number(band.away) || 0,
        );
    }
    return max;
});

const activeComparison = computed(() => {
    const bag = props.comparison || {};
    const rows = bag[comparePeriod.value];
    if (Array.isArray(rows) && rows.length) return rows;
    if (Array.isArray(bag.match)) return bag.match;
    return Array.isArray(props.comparison) ? props.comparison : [];
});

const percentComparison = computed(
    () => activeComparison.value.filter((row) => row?.unit === '%'),
);

/**
 * Номинальная шкала метрики: max(scale, home, away) — видно заполненность, не только «кто длиннее».
 * @param {{ home: number, away: number, scale?: number, unit?: string }} row
 */
function barScale(row) {
    if (row?.unit === '%') return 100;
    const scale = Number(row?.scale) || 0;
    return Math.max(scale, Number(row?.home) || 0, Number(row?.away) || 0, 0.01);
}

function isPercentMetric(row) {
    return row?.unit === '%';
}

function polar(cx, cy, r, angleDeg) {
    const a = ((angleDeg - 90) * Math.PI) / 180;
    return { x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) };
}

function pieSlicePath(cx, cy, r, startAngle, endAngle) {
    const sweep = endAngle - startAngle;
    if (sweep <= 0.01) return '';
    if (sweep >= 359.99) {
        const mid = startAngle + 180;
        const a = polar(cx, cy, r, startAngle);
        const b = polar(cx, cy, r, mid);
        const c = polar(cx, cy, r, startAngle + 360);
        return `M ${cx} ${cy} L ${a.x} ${a.y} A ${r} ${r} 0 1 1 ${b.x} ${b.y} A ${r} ${r} 0 1 1 ${c.x} ${c.y} Z`;
    }
    const large = sweep > 180 ? 1 : 0;
    const s = polar(cx, cy, r, startAngle);
    const e = polar(cx, cy, r, endAngle);
    return `M ${cx} ${cy} L ${s.x} ${s.y} A ${r} ${r} 0 ${large} 1 ${e.x} ${e.y} Z`;
}

/** Pie: секторы двух команд (доля от суммы значений). */
function pieSlices(row) {
    const h = Math.max(0, Number(row?.home) || 0);
    const a = Math.max(0, Number(row?.away) || 0);
    const sum = h + a;
    if (sum <= 0) {
        return {
            homePath: pieSlicePath(20, 20, 16, 0, 180),
            awayPath: pieSlicePath(20, 20, 16, 180, 360),
        };
    }
    const homeDeg = (h / sum) * 360;
    return {
        homePath: pieSlicePath(20, 20, 16, 0, homeDeg),
        awayPath: pieSlicePath(20, 20, 16, homeDeg, 360),
    };
}

function statusLabel(status) {
    if (status === 'live') return 'Идёт';
    if (status === 'finished') return 'Завершён';
    return 'Ожидается';
}

function formatKickoff(iso) {
    if (!iso) return 'Время уточняется';
    const d = new Date(iso);
    return new Intl.DateTimeFormat('ru-RU', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

function formatH2hDate(ymd) {
    if (!ymd) return '';
    const d = new Date(`${ymd}T12:00:00`);
    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(d);
}

function scoreText() {
    if (props.match?.score_home == null || props.match?.score_away == null) {
        return null;
    }
    return `${props.match.score_home}∶${props.match.score_away}`;
}

function formResults(side) {
    const key = side === 'away' ? 'form_away' : 'form_home';
    const raw = props.match?.[key];
    if (!Array.isArray(raw) || raw.length === 0) return [];
    return raw
        .map((r) => String(r).toUpperCase())
        .filter((r) => r === 'W' || r === 'D' || r === 'L');
}

function formStickClass(result) {
    if (result === 'W') return 'is-w';
    if (result === 'D') return 'is-d';
    return 'is-l';
}

function formResultLabel(result) {
    if (result === 'W') return 'П';
    if (result === 'D') return 'Н';
    if (result === 'L') return 'Пр';
    return result || '—';
}

function formResultTitle(result) {
    if (result === 'W') return 'Победа';
    if (result === 'D') return 'Ничья';
    if (result === 'L') return 'Поражение';
    return result || '';
}

function forecastMaxKey() {
    const f = props.forecast || {};
    const triples = [
        ['home', Number(f.home_pct) || 0],
        ['draw', Number(f.draw_pct) || 0],
        ['away', Number(f.away_pct) || 0],
    ];
    triples.sort((a, b) => b[1] - a[1]);
    return triples[0]?.[0] || 'home';
}

function weatherLine() {
    const w = props.match?.weather;
    if (!w) return null;
    const bits = [];
    if (w.condition) bits.push(w.condition);
    if (w.temp_c != null) bits.push(`${w.temp_c}°C`);
    if (w.wind_ms != null) bits.push(`ветер ${w.wind_ms} м/с`);
    return bits.length ? bits.join(' · ') : null;
}

function formatStat(value, unit) {
    if (typeof value !== 'number') return '—';
    if (unit === '%') return `${Math.round(value)}%`;
    if (Number.isInteger(value)) return String(value);
    return value.toFixed(1).replace(/\.0$/, '');
}

function barWidth(value, max) {
    const v = Number(value) || 0;
    const m = Number(max) || 0.01;
    return `${Math.max(6, Math.round((v / m) * 100))}%`;
}

function barHeight(value, max) {
    const v = Number(value) || 0;
    const m = Number(max) || 0.01;
    return `${Math.max(8, Math.round((v / m) * 100))}%`;
}

function absenceLabel(status) {
    return status === 'doubt' ? 'Сомнительно' : 'Вне заявки';
}

function onPick(outcome) {
    if (pickClosed.value) return;
    if (!user.value) {
        guestCtaOpen.value = true;
        return;
    }
    localPickId.value = localPickId.value === outcome.id ? null : outcome.id;
}

function isPicked(outcomeId) {
    return localPickId.value === outcomeId;
}

function marketHasPick(market) {
    return (market?.outcomes || []).some((o) => isPicked(o.id));
}

function loginHref() {
    return route('login');
}
</script>

<template>
    <Head :title="`${match.home} — ${match.away}`" />

    <AppLayout>
        <div class="miq-match-page space-y-5 sm:space-y-6">
            <!-- Back -->
            <div class="animate-miq-fade-up flex flex-wrap items-center gap-3">
                <Link
                    :href="backHref"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-miq-ink-soft transition hover:text-miq-pitch"
                >
                    <span aria-hidden="true">←</span>
                    Матчи
                </Link>
                <span
                    v-if="match.league_short"
                    class="miq-league-badge"
                    :title="match.league"
                >
                    {{ match.league_short }}
                </span>
            </div>

            <!-- 1. Header -->
            <section
                class="miq-panel animate-miq-fade-up overflow-hidden"
                style="animation-delay: 40ms"
                aria-label="Карточка матча"
            >
                <div class="miq-match-hero">
                    <div class="flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-miq-muted">
                        <span>{{ match.league }}</span>
                        <span v-if="match.round" class="text-miq-line" aria-hidden="true">·</span>
                        <span v-if="match.round">{{ match.round }}</span>
                        <span
                            class="miq-status-pill"
                            :class="{
                                'is-live': match.status === 'live',
                                'is-done': match.status === 'finished',
                            }"
                        >
                            {{ statusLabel(match.status) }}
                        </span>
                    </div>

                    <div class="miq-match-hero-teams">
                        <div class="miq-match-hero-side">
                            <p class="miq-display text-xl font-semibold leading-tight text-miq-ink sm:text-2xl">
                                {{ match.home }}
                            </p>
                            <div
                                v-if="formResults('home').length"
                                class="miq-form-sticks mt-2"
                                :aria-label="`Форма ${match.home}`"
                            >
                                <span
                                    v-for="(result, idx) in formResults('home')"
                                    :key="`hh-${idx}`"
                                    class="miq-form-stick"
                                    :class="formStickClass(result)"
                                    :title="formResultTitle(result)"
                                />
                            </div>
                        </div>

                        <div class="miq-match-hero-mid">
                            <p
                                v-if="scoreText()"
                                class="miq-display text-3xl font-bold tabular-nums leading-none"
                                :class="match.status === 'live' ? 'text-miq-live' : 'text-miq-ink'"
                            >
                                {{ scoreText() }}
                            </p>
                            <p
                                v-else
                                class="text-sm font-semibold uppercase tracking-wide text-miq-muted"
                            >
                                vs
                            </p>
                            <p class="mt-1.5 text-xs font-medium tabular-nums text-miq-ink-soft sm:text-sm">
                                {{ formatKickoff(match.kickoff_at) }}
                            </p>
                        </div>

                        <div class="miq-match-hero-side is-away">
                            <p class="miq-display text-xl font-semibold leading-tight text-miq-ink sm:text-2xl">
                                {{ match.away }}
                            </p>
                            <div
                                v-if="formResults('away').length"
                                class="miq-form-sticks mt-2 justify-end"
                                :aria-label="`Форма ${match.away}`"
                            >
                                <span
                                    v-for="(result, idx) in formResults('away')"
                                    :key="`ha-${idx}`"
                                    class="miq-form-stick"
                                    :class="formStickClass(result)"
                                    :title="formResultTitle(result)"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="match.venue || match.referee || weatherLine()"
                        class="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-miq-line/50 pt-3 text-xs text-miq-muted"
                    >
                        <p v-if="match.venue">
                            <span class="font-semibold text-miq-ink-soft">Стадион</span>
                            · {{ match.venue }}
                        </p>
                        <p v-if="match.referee">
                            <span class="font-semibold text-miq-ink-soft">Судья</span>
                            · {{ match.referee }}
                        </p>
                        <p v-if="weatherLine()">
                            <span class="font-semibold text-miq-ink-soft">Погода</span>
                            · {{ weatherLine() }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- 2. Forecast 1X2 + correct score -->
            <section
                v-if="forecast"
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 60ms"
                aria-label="Прогноз исхода"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Прогноз исхода</h2>
                    <p class="text-[11px] font-medium text-miq-muted">П1 · Н · П2 · модель</p>
                </div>

                <div class="miq-forecast-body">
                    <div class="miq-forecast-score" aria-label="Возможный счёт">
                        <p class="miq-forecast-score-label">Возможный счёт</p>
                        <p class="miq-forecast-score-value tabular-nums">
                            {{ forecast.score_home }}∶{{ forecast.score_away }}
                        </p>
                    </div>

                    <div class="miq-forecast-1x2" role="group" aria-label="Вероятности П1 · Н · П2">
                        <div
                            class="miq-forecast-cell is-team-home"
                            :class="{ 'is-lead': forecastMaxKey() === 'home' }"
                        >
                            <p class="miq-forecast-cell-label">П1</p>
                            <p class="miq-forecast-cell-team truncate">{{ match.home }}</p>
                            <p class="miq-forecast-cell-pct tabular-nums">
                                {{ forecast.home_pct }}<span>%</span>
                            </p>
                        </div>
                        <div
                            class="miq-forecast-cell is-draw"
                            :class="{ 'is-lead': forecastMaxKey() === 'draw' }"
                        >
                            <p class="miq-forecast-cell-label">Н</p>
                            <p class="miq-forecast-cell-team">Ничья</p>
                            <p class="miq-forecast-cell-pct tabular-nums">
                                {{ forecast.draw_pct }}<span>%</span>
                            </p>
                        </div>
                        <div
                            class="miq-forecast-cell is-team-away"
                            :class="{ 'is-lead': forecastMaxKey() === 'away' }"
                        >
                            <p class="miq-forecast-cell-label">П2</p>
                            <p class="miq-forecast-cell-team truncate">{{ match.away }}</p>
                            <p class="miq-forecast-cell-pct tabular-nums">
                                {{ forecast.away_pct }}<span>%</span>
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="forecast.correct_scores?.length"
                        class="miq-forecast-cs"
                    >
                        <p class="miq-forecast-cs-title">Точный счёт · топ</p>
                        <ul class="miq-forecast-cs-list">
                            <li
                                v-for="row in forecast.correct_scores"
                                :key="row.score"
                                class="miq-forecast-cs-item"
                            >
                                <span class="tabular-nums font-semibold text-miq-ink">{{ row.score }}</span>
                                <span class="tabular-nums text-miq-muted">{{ row.pct }}%</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 3. AI preview + insights -->
            <section
                v-if="preview || insights.length"
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 80ms"
                aria-label="Краткий прогноз модели"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Краткий прогноз модели</h2>
                    <p class="text-[11px] font-medium text-miq-muted">не гарантия</p>
                </div>
                <p
                    v-if="preview"
                    class="px-3.5 pt-3.5 text-sm leading-relaxed text-miq-ink-soft sm:text-[15px]"
                    :class="{ 'pb-3.5': !insights.length }"
                >
                    {{ preview }}
                </p>
                <ul
                    v-if="insights.length"
                    class="miq-insight-list"
                    :class="{ 'border-t border-miq-line/50': preview }"
                >
                    <li
                        v-for="(fact, idx) in insights"
                        :key="idx"
                        class="miq-insight-item"
                    >
                        {{ fact }}
                    </li>
                </ul>
            </section>

            <!-- 3. Markets (BK-style groups) -->
            <section
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 120ms"
                aria-label="События и вероятности"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">События</h2>
                    <p class="text-[11px] font-medium text-miq-muted">рынки · % ИИ</p>
                </div>

                <div class="px-2.5 pt-2.5 sm:px-3 sm:pt-3">
                    <div
                        class="inline-flex max-w-full flex-wrap rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                        role="tablist"
                        aria-label="Фильтр рынков"
                    >
                        <button
                            v-for="f in filters"
                            :key="f.id"
                            type="button"
                            role="tab"
                            class="miq-period"
                            :class="{ 'is-active': activeFilter === f.id }"
                            :aria-selected="activeFilter === f.id"
                            @click="activeFilter = f.id"
                        >
                            {{ f.label }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-[10px] leading-snug text-miq-muted sm:text-[11px]">
                        1 исход на матч · больше баллов за смелый верный выбор (низкая вероятность)
                    </p>
                    <p
                        v-if="pickClosed"
                        class="mt-1 text-[10px] font-semibold text-miq-warn sm:text-[11px]"
                    >
                        Выбор закрыт — вероятности как снимок на старт
                    </p>
                </div>

                <div
                    v-if="guestCtaOpen && !user"
                    class="mx-2.5 mt-2 rounded-md border border-miq-pitch/25 bg-[color-mix(in_srgb,var(--miq-pitch)_8%,white)] px-2.5 py-2 text-sm text-miq-ink-soft sm:mx-3"
                    role="status"
                >
                    Чтобы выбрать исход в рейтинг,
                    <Link :href="loginHref()" class="font-semibold text-miq-pitch underline-offset-2 hover:underline">
                        войдите
                    </Link>
                    или
                    <Link :href="route('register')" class="font-semibold text-miq-pitch underline-offset-2 hover:underline">
                        зарегистрируйтесь
                    </Link>.
                </div>

                <ul
                    v-if="filteredMarkets.length"
                    class="mt-2 space-y-1.5 px-2.5 pb-2.5 sm:px-3 sm:pb-3"
                >
                    <li
                        v-for="market in filteredMarkets"
                        :key="market.id"
                        class="miq-market-card"
                        :class="{ 'has-pick': marketHasPick(market) }"
                    >
                        <div class="miq-market-head">
                            <h3 class="miq-market-name">{{ market.name }}</h3>
                            <p
                                v-if="marketPopular(market)"
                                class="miq-market-pop"
                            >
                                топ {{ marketPopular(market) }}%
                            </p>
                        </div>

                        <div
                            class="miq-outcome-grid"
                            :class="{
                                'is-triple': (market.outcomes || []).length === 3,
                                'is-binary': (market.outcomes || []).length === 2,
                            }"
                            role="group"
                            :aria-label="market.name"
                        >
                            <div
                                v-for="outcome in market.outcomes"
                                :key="outcome.id"
                                class="miq-outcome-cell"
                                :class="{ 'is-picked': isPicked(outcome.id) }"
                            >
                                <p class="miq-outcome-label">{{ outcome.label }}</p>
                                <p class="miq-outcome-prob tabular-nums">
                                    {{ outcome.prob }}<span class="miq-outcome-unit">%</span>
                                </p>
                                <p class="miq-outcome-meta">
                                    <span>выбрали {{ outcome.popular_pct }}%</span>
                                    <span
                                        v-if="outcome.hit === true"
                                        class="miq-outcome-hit is-yes"
                                    >· зашло</span>
                                    <span
                                        v-else-if="outcome.hit === false"
                                        class="miq-outcome-hit"
                                    >· мимо</span>
                                </p>
                                <button
                                    v-if="!pickClosed"
                                    type="button"
                                    class="miq-pick-btn miq-outcome-pick"
                                    :class="{ 'is-active': isPicked(outcome.id) }"
                                    :aria-pressed="isPicked(outcome.id)"
                                    @click="onPick(outcome)"
                                >
                                    {{ isPicked(outcome.id) ? 'Выбрано' : 'Выбрать' }}
                                </button>
                                <span
                                    v-else-if="isPicked(outcome.id)"
                                    class="miq-pick-btn miq-outcome-pick is-active is-locked"
                                >
                                    Ваш выбор
                                </span>
                            </div>
                        </div>
                    </li>
                </ul>
                <div
                    v-else
                    class="px-4 py-8 text-center text-sm text-miq-muted"
                >
                    Вероятности пока считаются
                </div>
            </section>

            <!-- Trends / streaks -->
            <section
                v-if="trends?.home?.length || trends?.away?.length"
                class="miq-panel animate-miq-fade-up"
                aria-label="Тренды и серии"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Тренды и серии</h2>
                    <p class="text-[11px] font-medium text-miq-muted">последние 5</p>
                </div>
                <div class="miq-trends-grid">
                    <div
                        v-for="side in [
                            { key: 'home', team: match.home, rows: trends.home || [] },
                            { key: 'away', team: match.away, rows: trends.away || [] },
                        ]"
                        :key="side.key"
                        class="miq-trends-side"
                    >
                        <p
                            class="miq-trends-team"
                            :class="side.key === 'home' ? 'is-team-home' : 'is-team-away'"
                        >
                            {{ side.team }}
                        </p>
                        <ul class="miq-trends-list">
                            <li
                                v-for="row in side.rows"
                                :key="`${side.key}-${row.key}`"
                                class="miq-trends-row"
                            >
                                <span class="miq-trends-label">{{ row.label }}</span>
                                <span class="miq-trends-value tabular-nums">
                                    {{ row.value }}
                                    <span class="miq-trends-unit">{{ row.unit }}</span>
                                </span>
                                <span class="miq-trends-hint">{{ row.hint }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Goal averages / тайм-матч / halves -->
            <section
                v-if="goalProfile?.averages"
                class="miq-panel animate-miq-fade-up"
                aria-label="Голы и таймы"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Голы · тайм/матч · таймы</h2>
                    <p class="text-[11px] font-medium text-miq-muted">средние</p>
                </div>

                <div class="miq-goalprof-avgs">
                    <div
                        v-for="side in [goalProfile.averages.home, goalProfile.averages.away]"
                        :key="side.team"
                        class="miq-goalprof-avg-card"
                    >
                        <p class="miq-goalprof-avg-team truncate">{{ side.team }}</p>
                        <dl class="miq-goalprof-avg-grid">
                            <div>
                                <dt>ЗП</dt>
                                <dd class="tabular-nums">{{ formatStat(side.gf) }}</dd>
                            </div>
                            <div>
                                <dt>ПР</dt>
                                <dd class="tabular-nums">{{ formatStat(side.ga) }}</dd>
                            </div>
                            <div>
                                <dt>Тотал</dt>
                                <dd class="tabular-nums">{{ formatStat(side.total) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <p
                        v-if="goalProfile.averages.match_exp != null"
                        class="miq-goalprof-exp"
                    >
                        Ожидаемый тотал матча
                        <strong class="tabular-nums">{{ formatStat(goalProfile.averages.match_exp) }}</strong>
                    </p>
                </div>

                <div
                    v-if="goalProfile.ht_ft?.length"
                    class="miq-goalprof-htft"
                >
                    <p class="miq-goalprof-subhead">Тайм / матч</p>
                    <ul class="miq-goalprof-htft-list">
                        <li
                            v-for="row in goalProfile.ht_ft"
                            :key="row.label"
                            class="miq-goalprof-htft-item"
                        >
                            <span class="font-semibold tabular-nums text-miq-ink">{{ row.label }}</span>
                            <span class="tabular-nums text-miq-muted">{{ row.pct }}%</span>
                        </li>
                    </ul>
                    <p class="miq-goalprof-htft-hint">
                        Первый символ — исход 1-го тайма, второй — исход матча;
                        1 — хозяева, Н — ничья, 2 — гости.
                    </p>
                </div>

                <div
                    v-if="goalProfile.halves"
                    class="miq-goalprof-halves"
                >
                    <div
                        v-for="half in [goalProfile.halves.first, goalProfile.halves.second]"
                        :key="half.label"
                        class="miq-goalprof-half"
                    >
                        <p class="miq-goalprof-subhead">{{ half.label }}</p>
                        <dl class="miq-goalprof-half-grid">
                            <div>
                                <dt>Ср. голы</dt>
                                <dd class="tabular-nums">{{ formatStat(half.avg) }}</dd>
                            </div>
                            <div>
                                <dt>ТБ 0.5 (1+ гол)</dt>
                                <dd class="tabular-nums">{{ half.over_05 }}%</dd>
                            </div>
                            <div>
                                <dt>ТБ 1.5 (2+ гола)</dt>
                                <dd class="tabular-nums">{{ half.over_15 }}%</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- Comparison last 5 — final: B (default) | C + period -->
            <section
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 160ms"
                aria-label="Последние 5 матчей"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Последние 5 матчей</h2>
                    <p class="truncate text-[10px] font-medium text-miq-muted">
                        цвета команд · шкала от нормы
                    </p>
                </div>

                <div class="flex flex-col gap-2 px-3 pt-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div
                        class="inline-flex max-w-full flex-wrap rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                        role="tablist"
                        aria-label="Период сравнения"
                    >
                        <button
                            v-for="p in comparePeriods"
                            :key="p.id"
                            type="button"
                            role="tab"
                            class="miq-period"
                            :class="{ 'is-active': comparePeriod === p.id }"
                            :aria-selected="comparePeriod === p.id"
                            @click="comparePeriod = p.id"
                        >
                            {{ p.label }}
                        </button>
                    </div>
                    <div
                        class="inline-flex max-w-full flex-wrap rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                        role="tablist"
                        aria-label="Вид сравнения"
                    >
                        <button
                            v-for="v in compareViews"
                            :key="v.id"
                            type="button"
                            role="tab"
                            class="miq-period miq-period-icon"
                            :class="{ 'is-active': compareView === v.id }"
                            :aria-selected="compareView === v.id"
                            :title="v.title"
                            @click="compareView = v.id"
                        >
                            <svg
                                v-if="v.icon === 'bars'"
                                class="miq-period-svg"
                                viewBox="0 0 16 16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                stroke-linecap="round"
                                aria-hidden="true"
                            >
                                <path d="M3 12.5V7.5M8 12.5V3.5M13 12.5V6" />
                            </svg>
                            <svg
                                v-else
                                class="miq-period-svg"
                                viewBox="0 0 16 16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <circle cx="8" cy="8" r="5.25" />
                                <path d="M8 2.75V8l3.75 2.1" />
                            </svg>
                            <span>{{ v.label }}</span>
                        </button>
                    </div>
                </div>

                <div class="miq-compare-legend px-3.5 pt-2.5">
                    <span class="miq-compare-legend-item">
                        <span class="miq-compare-legend-swatch is-team-home" aria-hidden="true" />
                        {{ match.home }}
                        <span v-if="compareView === 'b'" class="font-medium text-miq-muted">· верх</span>
                    </span>
                    <span class="miq-compare-legend-item">
                        <span class="miq-compare-legend-swatch is-team-away" aria-hidden="true" />
                        {{ match.away }}
                        <span v-if="compareView === 'b'" class="font-medium text-miq-muted">· низ</span>
                    </span>
                </div>

                <!-- View B: oneside bars + pie for % -->
                <div
                    v-if="compareView === 'b'"
                    class="miq-compare-ab-grid"
                >
                    <ul class="miq-compare-ab-bars divide-y divide-miq-line/40">
                        <li
                            v-for="row in activeComparison"
                            :key="`b-${comparePeriod}-${row.key}`"
                            class="miq-compare-row is-team"
                        >
                            <span class="miq-compare-label">{{ row.label }}</span>
                            <div class="miq-compare-bars" aria-hidden="true">
                                <div class="miq-compare-bar is-team-home">
                                    <span :style="{ width: barWidth(row.home, barScale(row)) }" />
                                </div>
                                <div class="miq-compare-bar is-team-away">
                                    <span :style="{ width: barWidth(row.away, barScale(row)) }" />
                                </div>
                            </div>
                            <div class="miq-compare-nums tabular-nums is-team">
                                <span class="is-team-home">{{ formatStat(row.home, row.unit) }}</span>
                                <span class="is-team-away">{{ formatStat(row.away, row.unit) }}</span>
                            </div>
                        </li>
                    </ul>

                    <div class="miq-compare-ab-donuts" aria-label="Круги для процентов">
                        <p class="miq-compare-ab-donuts-title">Только %</p>
                        <ul class="miq-compare-ab-donuts-list">
                            <li
                                v-for="row in percentComparison"
                                :key="`b-pie-${comparePeriod}-${row.key}`"
                                class="miq-donut-metric miq-compare-ab-donut-item"
                            >
                                <p class="miq-donut-metric-label">{{ row.label }}</p>
                                <div class="miq-pie-block">
                                    <svg class="miq-pie miq-pie-side" viewBox="0 0 40 40" aria-hidden="true">
                                        <path
                                            class="miq-pie-slice is-team-home"
                                            :d="pieSlices(row).homePath"
                                        />
                                        <path
                                            class="miq-pie-slice is-team-away"
                                            :d="pieSlices(row).awayPath"
                                        />
                                    </svg>
                                    <div class="miq-pie-vals miq-pie-vals-side tabular-nums">
                                        <span class="is-team-home">{{ formatStat(row.home, '%') }}</span>
                                        <span class="is-team-away">{{ formatStat(row.away, '%') }}</span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- View C: all metrics as pie -->
                <ul
                    v-else
                    class="miq-donut-grid px-3 pb-3.5 pt-2"
                >
                    <li
                        v-for="row in activeComparison"
                        :key="`c-${comparePeriod}-${row.key}`"
                        class="miq-donut-metric is-card"
                    >
                        <p class="miq-donut-metric-label">{{ row.label }}</p>
                        <div class="miq-pie-block">
                            <svg class="miq-pie is-lg" viewBox="0 0 40 40" aria-hidden="true">
                                <path
                                    class="miq-pie-slice is-team-home"
                                    :d="pieSlices(row).homePath"
                                />
                                <path
                                    class="miq-pie-slice is-team-away"
                                    :d="pieSlices(row).awayPath"
                                />
                            </svg>
                            <div class="miq-pie-vals tabular-nums">
                                <span class="is-team-home">{{ formatStat(row.home, row.unit) }}</span>
                                <span class="is-team-away">{{ formatStat(row.away, row.unit) }}</span>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Extra: home/away splits -->
            <section
                v-if="activeHomeAway"
                class="miq-panel animate-miq-fade-up"
                aria-label="Дома и в гостях"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Дома / в гостях</h2>
                    <p class="text-[11px] text-miq-muted">средние</p>
                </div>

                <div class="px-3 pt-2.5">
                    <div
                        class="inline-flex max-w-full flex-wrap rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                        role="tablist"
                        aria-label="Выборка средних"
                    >
                        <button
                            v-for="s in homeAwaySamples"
                            :key="s.id"
                            type="button"
                            role="tab"
                            class="miq-period"
                            :class="{ 'is-active': homeAwaySample === s.id }"
                            :aria-selected="homeAwaySample === s.id"
                            @click="homeAwaySample = s.id"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 px-3.5 py-3.5 sm:grid-cols-2">
                    <div
                        v-for="side in [activeHomeAway.home, activeHomeAway.away]"
                        :key="side.team + side.context + homeAwaySample"
                        class="miq-split-card"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide text-miq-muted">
                            {{ side.team }} · {{ side.context }}
                        </p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Забито</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.gf) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Пропущено</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.ga) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">xG</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.xg) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Угловые</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.corners) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">ЖК</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.yellows) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Удары в створ</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.sot) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Фолы</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.fouls) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase tracking-wide text-miq-muted">Офсайды</dt>
                                <dd class="font-semibold tabular-nums text-miq-ink">{{ formatStat(side.offsides) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- 5 + 6: standings + scorers -->
            <div class="grid gap-5 lg:grid-cols-2 lg:gap-6">
                <section class="miq-panel" aria-label="Мини-таблица">
                    <div class="miq-panel-head">
                        <h2 class="miq-panel-title">Таблица</h2>
                        <p class="truncate text-xs text-miq-muted">{{ match.league_short }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[26rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-miq-line/60 text-[10px] uppercase tracking-wider text-miq-muted">
                                    <th class="px-2 py-2 font-semibold sm:px-3">#</th>
                                    <th class="px-1 py-2 font-semibold">Команда</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Игры">И</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Победы">В</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Ничьи">Н</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Забитые">ЗП</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Пропущенные">ПР</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Разница">+/−</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Очки">О</th>
                                    <th class="px-1.5 py-2 text-right font-semibold" title="Очки за игру">О/И</th>
                                    <th class="px-2 py-2 text-right font-semibold sm:px-3" title="Форма · последние 5">Ф</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in standings"
                                    :key="row.pos"
                                    class="border-b border-miq-line/40 last:border-0"
                                    :class="{ 'miq-table-highlight': row.highlight }"
                                >
                                    <td class="px-2 py-2 sm:px-3">
                                        <span class="miq-rank" :class="{ 'is-top': row.pos <= 3 }">
                                            {{ row.pos }}
                                        </span>
                                    </td>
                                    <td class="truncate px-1 py-2 font-medium text-miq-ink">
                                        {{ row.team }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.played }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.wins }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.draws }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.gf }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.ga }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.gd > 0 ? `+${row.gd}` : row.gd }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right font-semibold tabular-nums text-miq-ink">
                                        {{ row.pts }}
                                    </td>
                                    <td class="px-1.5 py-2 text-right tabular-nums text-miq-muted">
                                        {{ row.ppg != null ? formatStat(row.ppg) : '—' }}
                                    </td>
                                    <td class="px-2 py-2 text-right sm:px-3">
                                        <span
                                            v-if="row.form"
                                            class="miq-table-form tabular-nums"
                                            :title="`Форма: ${row.form}`"
                                        >{{ row.form }}</span>
                                        <span v-else class="text-miq-muted">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="miq-panel" aria-label="Бомбардиры команд">
                    <div class="miq-panel-head">
                        <h2 class="miq-panel-title">Бомбардиры</h2>
                        <p class="text-xs text-miq-muted">по командам</p>
                    </div>
                    <div class="grid gap-3 px-3.5 py-3 sm:grid-cols-2">
                        <div>
                            <p class="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-miq-pitch">
                                {{ match.home }}
                            </p>
                            <ul class="space-y-2">
                                <li
                                    v-for="(row, idx) in scorers.home"
                                    :key="`sh-${idx}`"
                                    class="flex items-baseline justify-between gap-2 text-sm"
                                >
                                    <span class="min-w-0 truncate font-medium text-miq-ink">{{ row.player }}</span>
                                    <span class="shrink-0 font-bold tabular-nums text-miq-pitch-deep">
                                        {{ row.goals }}
                                        <span
                                            v-if="row.assists != null"
                                            class="font-medium text-miq-muted"
                                        >+{{ row.assists }}</span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <p class="mb-1.5 text-[11px] font-bold uppercase tracking-wide" style="color: var(--miq-chip-away)">
                                {{ match.away }}
                            </p>
                            <ul class="space-y-2">
                                <li
                                    v-for="(row, idx) in scorers.away"
                                    :key="`sa-${idx}`"
                                    class="flex items-baseline justify-between gap-2 text-sm"
                                >
                                    <span class="min-w-0 truncate font-medium text-miq-ink">{{ row.player }}</span>
                                    <span class="shrink-0 font-bold tabular-nums text-miq-pitch-deep">
                                        {{ row.goals }}
                                        <span
                                            v-if="row.assists != null"
                                            class="font-medium text-miq-muted"
                                        >+{{ row.assists }}</span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>

            <!-- 7. Absences -->
            <section class="miq-panel" aria-label="Потери">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Потери</h2>
                    <p class="text-xs text-miq-muted">травмы / дисквалификации</p>
                </div>
                <div class="grid gap-3 px-3.5 py-3.5 sm:grid-cols-2">
                    <div
                        v-for="side in [
                            { team: match.home, list: absences.home || [] },
                            { team: match.away, list: absences.away || [] },
                        ]"
                        :key="side.team"
                    >
                        <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-miq-muted">
                            {{ side.team }}
                        </p>
                        <ul v-if="side.list.length" class="space-y-2">
                            <li
                                v-for="(row, idx) in side.list"
                                :key="`${side.team}-${idx}`"
                                class="flex items-start justify-between gap-2 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="font-semibold text-miq-ink">{{ row.player }}</p>
                                    <p class="text-[11px] text-miq-muted">{{ row.reason }}</p>
                                </div>
                                <span
                                    class="miq-absence-tag shrink-0"
                                    :class="row.status === 'doubt' ? 'is-doubt' : 'is-out'"
                                >
                                    {{ absenceLabel(row.status) }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-miq-muted">Без известных потерь</p>
                    </div>
                </div>
            </section>

            <!-- 8. Goal minutes — vertical paired columns (суммы, номинальная шкала) -->
            <section class="miq-panel" aria-label="Минуты голов">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Голы по минутам</h2>
                </div>

                <div class="miq-compare-legend px-3.5 pt-2.5">
                    <span class="miq-compare-legend-item">
                        <span class="miq-compare-legend-swatch is-team-home" aria-hidden="true" />
                        {{ match.home }}
                    </span>
                    <span class="miq-compare-legend-item">
                        <span class="miq-compare-legend-swatch is-team-away" aria-hidden="true" />
                        {{ match.away }}
                    </span>
                </div>

                <div
                    class="miq-minute-chart px-3 pb-3.5 pt-2"
                    role="img"
                    :aria-label="`Голы по интервалам: ${match.home} и ${match.away}`"
                >
                    <div
                        v-for="band in goalMinutes"
                        :key="band.band"
                        class="miq-minute-col"
                    >
                        <div class="miq-minute-vpair" aria-hidden="true">
                            <div class="miq-minute-vbar is-team-home" :title="`${match.home}: ${formatStat(band.home)}`">
                                <span :style="{ height: barHeight(band.home, goalMax) }" />
                            </div>
                            <div class="miq-minute-vbar is-team-away" :title="`${match.away}: ${formatStat(band.away)}`">
                                <span :style="{ height: barHeight(band.away, goalMax) }" />
                            </div>
                        </div>
                        <div class="miq-minute-vals tabular-nums">
                            <span class="is-team-home">{{ formatStat(band.home) }}</span>
                            <span class="is-team-away">{{ formatStat(band.away) }}</span>
                        </div>
                        <span class="miq-minute-xlabel tabular-nums">{{ band.band }}</span>
                    </div>
                </div>
            </section>

            <!-- 9. Form blocks — 5 карточек -->
            <section class="space-y-3.5" aria-label="Форма">
                <article
                    v-for="block in formBlocks"
                    :key="block.id"
                    class="miq-panel miq-form-block-card"
                >
                    <div class="miq-panel-head">
                        <h2 class="miq-panel-title">{{ block.title }}</h2>
                    </div>
                    <ul class="miq-form-match-list">
                        <li
                            v-for="(m, idx) in block.matches"
                            :key="`${block.id}-${idx}`"
                            class="miq-form-match-row"
                        >
                            <time class="miq-form-match-date tabular-nums" :datetime="m.date">
                                {{ formatH2hDate(m.date) }}
                            </time>
                            <p class="miq-form-match-pair">
                                <span class="miq-form-match-team">{{ m.home }}</span>
                                <span class="miq-form-match-score tabular-nums">
                                    {{ m.score_home }}∶{{ m.score_away }}
                                </span>
                                <span class="miq-form-match-team">{{ m.away }}</span>
                            </p>
                            <span
                                class="miq-form-wdl"
                                :class="formStickClass(m.result)"
                                :title="formResultTitle(m.result)"
                            >
                                {{ formResultLabel(m.result) }}
                            </span>
                        </li>
                    </ul>
                </article>
            </section>

            <!-- 10. Comments -->
            <section class="miq-panel" aria-label="Комментарии">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Комментарии</h2>
                    <p class="text-xs text-miq-muted">демо</p>
                </div>
                <ul class="divide-y divide-miq-line/50">
                    <li
                        v-for="c in comments"
                        :key="c.id"
                        class="px-3.5 py-3"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold text-miq-ink">{{ c.author }}</p>
                            <p class="shrink-0 text-[11px] text-miq-muted">{{ c.ago }}</p>
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-miq-ink-soft">
                            {{ c.body }}
                        </p>
                        <p class="mt-1.5 text-[11px] font-medium text-miq-muted">
                            ▲ {{ c.likes }}
                        </p>
                    </li>
                </ul>
                <p
                    v-if="!user"
                    class="border-t border-miq-line/50 px-3.5 py-3 text-sm text-miq-muted"
                >
                    <Link :href="loginHref()" class="font-semibold text-miq-pitch hover:underline">
                        Войдите
                    </Link>,
                    чтобы оставить комментарий.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
