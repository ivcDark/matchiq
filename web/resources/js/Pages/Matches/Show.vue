<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    match: { type: Object, required: true },
    preview: { type: String, default: null },
    events: { type: Array, default: () => [] },
    comparison: { type: Array, default: () => [] },
    homeAway: { type: Object, default: () => ({ home: null, away: null }) },
    standings: { type: Array, default: () => [] },
    scorers: { type: Object, default: () => ({ home: [], away: [] }) },
    absences: { type: Object, default: () => ({ home: [], away: [] }) },
    goalMinutes: { type: Array, default: () => [] },
    h2h: { type: Object, default: () => ({ summary: null, meetings: [] }) },
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

const filteredEvents = computed(() => {
    const list = [...(props.events || [])];
    if (activeFilter.value === 'worst') {
        list.sort((a, b) => a.prob - b.prob);
    } else if (activeFilter.value === 'popular') {
        list.sort((a, b) => (b.popular_pct ?? 0) - (a.popular_pct ?? 0));
    } else {
        list.sort((a, b) => b.prob - a.prob);
    }
    return list;
});

const goalMax = computed(() => {
    let max = 0.01;
    for (const band of props.goalMinutes || []) {
        max = Math.max(max, Number(band.home) || 0, Number(band.away) || 0);
    }
    return max;
});

const comparisonMax = computed(() => {
    const map = {};
    for (const row of props.comparison || []) {
        map[row.key] = Math.max(Number(row.home) || 0, Number(row.away) || 0, 0.01);
    }
    return map;
});

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

function absenceLabel(status) {
    return status === 'doubt' ? 'Сомнительно' : 'Вне заявки';
}

function onPick(event) {
    if (pickClosed.value) return;
    if (!user.value) {
        guestCtaOpen.value = true;
        return;
    }
    localPickId.value = localPickId.value === event.id ? null : event.id;
}

function isPicked(eventId) {
    return localPickId.value === eventId;
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
                                    :title="result"
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
                                    :title="result"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="match.venue || match.referee"
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
                    </div>
                </div>
            </section>

            <!-- 2. AI preview -->
            <section
                v-if="preview"
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 80ms"
                aria-label="Краткий прогноз модели"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Краткий прогноз модели</h2>
                    <p class="text-[11px] font-medium text-miq-muted">не гарантия</p>
                </div>
                <p class="px-3.5 py-3.5 text-sm leading-relaxed text-miq-ink-soft sm:text-[15px]">
                    {{ preview }}
                </p>
            </section>

            <!-- 3. Markets / events -->
            <section
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 120ms"
                aria-label="События и вероятности"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">События</h2>
                    <p class="text-[11px] font-medium text-miq-muted">0–100</p>
                </div>

                <div class="px-3 pt-3">
                    <div
                        class="inline-flex max-w-full flex-wrap rounded-md border border-miq-line/80 bg-white/60 p-0.5"
                        role="tablist"
                        aria-label="Фильтр событий"
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
                    <p class="mt-2 text-[11px] leading-snug text-miq-muted">
                        1 событие на матч · больше баллов за смелый верный выбор (низкая вероятность)
                    </p>
                    <p
                        v-if="pickClosed"
                        class="mt-1 text-[11px] font-semibold text-miq-warn"
                    >
                        Выбор закрыт — вероятности как снимок на kickoff
                    </p>
                </div>

                <div
                    v-if="guestCtaOpen && !user"
                    class="mx-3.5 mt-3 rounded-md border border-miq-pitch/25 bg-[color-mix(in_srgb,var(--miq-pitch)_8%,white)] px-3 py-2.5 text-sm text-miq-ink-soft"
                    role="status"
                >
                    Чтобы выбрать событие в рейтинг,
                    <Link :href="loginHref()" class="font-semibold text-miq-pitch underline-offset-2 hover:underline">
                        войдите
                    </Link>
                    или
                    <Link :href="route('register')" class="font-semibold text-miq-pitch underline-offset-2 hover:underline">
                        зарегистрируйтесь
                    </Link>.
                </div>

                <ul
                    v-if="filteredEvents.length"
                    class="mt-2 divide-y divide-miq-line/50"
                >
                    <li
                        v-for="event in filteredEvents"
                        :key="event.id"
                        class="miq-event-row"
                        :class="{ 'is-picked': isPicked(event.id) }"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-miq-ink">
                                {{ event.label }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-miq-muted">
                                Выбрали {{ event.popular_pct }}%
                                <span
                                    v-if="event.hit === true"
                                    class="ml-1 font-semibold text-miq-pitch"
                                >· зашло</span>
                                <span
                                    v-else-if="event.hit === false"
                                    class="ml-1 font-semibold text-miq-muted"
                                >· мимо</span>
                            </p>
                        </div>

                        <p class="miq-event-prob tabular-nums">
                            {{ event.prob }}
                            <span class="miq-event-prob-unit">%</span>
                        </p>

                        <button
                            v-if="!pickClosed"
                            type="button"
                            class="miq-pick-btn"
                            :class="{ 'is-active': isPicked(event.id) }"
                            :aria-pressed="isPicked(event.id)"
                            @click="onPick(event)"
                        >
                            {{ isPicked(event.id) ? 'Выбрано' : 'Выбрать' }}
                        </button>
                        <span
                            v-else-if="isPicked(event.id)"
                            class="miq-pick-btn is-active is-locked"
                        >
                            Ваш pick
                        </span>
                    </li>
                </ul>
                <div
                    v-else
                    class="px-4 py-8 text-center text-sm text-miq-muted"
                >
                    Вероятности пока считаются
                </div>
            </section>

            <!-- 4. Comparison last 5 -->
            <section
                class="miq-panel animate-miq-fade-up"
                style="animation-delay: 160ms"
                aria-label="Сравнение за 5 матчей"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Сравнение · последние 5</h2>
                    <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wide">
                        <span class="text-miq-pitch">{{ match.home }}</span>
                        <span style="color: var(--miq-chip-away)">{{ match.away }}</span>
                    </div>
                </div>

                <ul class="divide-y divide-miq-line/40 px-3.5 py-1">
                    <li
                        v-for="row in comparison"
                        :key="row.key"
                        class="miq-compare-row"
                    >
                        <div class="miq-compare-vals">
                            <span class="tabular-nums font-semibold text-miq-pitch-deep">
                                {{ formatStat(row.home, row.unit) }}
                            </span>
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-miq-muted">
                                {{ row.label }}
                            </span>
                            <span class="tabular-nums font-semibold" style="color: var(--miq-chip-away)">
                                {{ formatStat(row.away, row.unit) }}
                            </span>
                        </div>
                        <div class="miq-compare-bars" aria-hidden="true">
                            <div class="miq-compare-bar is-home">
                                <span
                                    :style="{ width: barWidth(row.home, comparisonMax[row.key]) }"
                                />
                            </div>
                            <div class="miq-compare-bar is-away">
                                <span
                                    :style="{ width: barWidth(row.away, comparisonMax[row.key]) }"
                                />
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Extra: home/away splits -->
            <section
                v-if="homeAway?.home && homeAway?.away"
                class="miq-panel animate-miq-fade-up"
                aria-label="Дома и в гостях"
            >
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Дома / в гостях</h2>
                    <p class="text-[11px] text-miq-muted">средние</p>
                </div>
                <div class="grid gap-3 px-3.5 py-3.5 sm:grid-cols-2">
                    <div
                        v-for="side in [homeAway.home, homeAway.away]"
                        :key="side.team + side.context"
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
                        <table class="w-full min-w-[15rem] text-left text-sm">
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
                                    v-for="row in standings"
                                    :key="row.pos"
                                    class="border-b border-miq-line/40 last:border-0"
                                    :class="{ 'miq-table-highlight': row.highlight }"
                                >
                                    <td class="px-3 py-2">
                                        <span class="miq-rank" :class="{ 'is-top': row.pos <= 3 }">
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
                                        {{ row.gd > 0 ? `+${row.gd}` : row.gd }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-miq-ink">
                                        {{ row.pts }}
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

            <!-- 8. Goal minutes -->
            <section class="miq-panel" aria-label="Минуты голов">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Голы по минутам</h2>
                    <p class="text-xs text-miq-muted">средние</p>
                </div>
                <ul class="space-y-2.5 px-3.5 py-3.5">
                    <li
                        v-for="band in goalMinutes"
                        :key="band.band"
                        class="miq-minute-row"
                    >
                        <span class="miq-minute-label tabular-nums">{{ band.band }}</span>
                        <div class="miq-minute-bars" aria-hidden="true">
                            <div class="miq-minute-bar is-home">
                                <span :style="{ width: barWidth(band.home, goalMax) }" />
                            </div>
                            <div class="miq-minute-bar is-away">
                                <span :style="{ width: barWidth(band.away, goalMax) }" />
                            </div>
                        </div>
                        <div class="miq-minute-nums tabular-nums">
                            <span class="text-miq-pitch-deep">{{ formatStat(band.home) }}</span>
                            <span style="color: var(--miq-chip-away)">{{ formatStat(band.away) }}</span>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- 9. Form + H2H -->
            <section class="miq-panel" aria-label="Форма и очные">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Форма и H2H</h2>
                </div>
                <div class="space-y-4 px-3.5 py-3.5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="side in [
                                { team: match.home, results: formResults('home') },
                                { team: match.away, results: formResults('away') },
                            ]"
                            :key="side.team"
                            class="flex items-center justify-between gap-3"
                        >
                            <p class="min-w-0 truncate text-sm font-semibold text-miq-ink">
                                {{ side.team }}
                            </p>
                            <div class="miq-form-sticks shrink-0">
                                <span
                                    v-for="(result, idx) in side.results"
                                    :key="`${side.team}-f-${idx}`"
                                    class="miq-form-stick"
                                    :class="formStickClass(result)"
                                    :title="result"
                                />
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="h2h?.summary"
                        class="text-sm leading-relaxed text-miq-ink-soft"
                    >
                        {{ h2h.summary }}
                    </p>

                    <ul
                        v-if="h2h?.meetings?.length"
                        class="divide-y divide-miq-line/50 rounded-md border border-miq-line/60 overflow-hidden"
                    >
                        <li
                            v-for="(m, idx) in h2h.meetings"
                            :key="idx"
                            class="flex flex-wrap items-center justify-between gap-2 bg-white/50 px-3 py-2.5 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="font-medium text-miq-ink">
                                    {{ m.home }} {{ m.score_home }}∶{{ m.score_away }} {{ m.away }}
                                </p>
                                <p class="text-[11px] text-miq-muted">
                                    {{ formatH2hDate(m.date) }}
                                    <span v-if="m.competition"> · {{ m.competition }}</span>
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
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
