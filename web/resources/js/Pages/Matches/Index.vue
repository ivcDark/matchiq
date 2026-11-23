<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    leagues: { type: Array, default: () => [] },
    matches: { type: Array, default: () => [] },
    selectedLeague: { type: String, default: 'rpl' },
    period: { type: String, default: 'upcoming' },
});

const periods = [
    { id: 'today', label: 'Сегодня' },
    { id: 'upcoming', label: 'Ближайшие' },
    { id: 'finished', label: 'Завершённые' },
];

const soonHint = ref(null);
let soonTimer = null;

const activeLeague = computed(() =>
    props.leagues.find((l) => l.id === props.selectedLeague) ?? null,
);

const leagueHint = computed(() => {
    if (!activeLeague.value) {
        return null;
    }
    return `${activeLeague.value.country} · ${activeLeague.value.name}`;
});

const filteredMatches = computed(() => {
    return props.matches.filter((m) => {
        if (m.league_id !== props.selectedLeague) {
            return false;
        }
        if (props.period === 'today') {
            return m.period_bucket === 'today';
        }
        if (props.period === 'finished') {
            return m.period_bucket === 'finished' || m.status === 'finished';
        }
        // upcoming: today + upcoming scheduled/live (not finished)
        return m.period_bucket === 'upcoming' || m.period_bucket === 'today';
    });
});

const hasLiveLeagues = computed(() =>
    props.leagues.some((l) => l.status === 'live'),
);

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
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

function scoreText(match) {
    if (match.score_home == null || match.score_away == null) {
        return null;
    }
    return `${match.score_home}∶${match.score_away}`;
}

const listKey = computed(
    () => `${props.selectedLeague}-${props.period}`,
);
</script>

<template>
    <Head title="Матчи" />

    <AppLayout :league-hint="leagueHint">
        <section class="animate-miq-fade-up" style="animation-delay: 80ms">
            <p class="text-base font-semibold text-miq-ink-soft sm:text-lg">
                Вероятности событий по матчам
            </p>
            <p class="mt-1 max-w-md text-sm leading-relaxed text-miq-muted">
                Откройте матч → события 0–100 → выберите одно в рейтинг.
            </p>
        </section>

        <section class="mt-7" aria-label="Лиги">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-miq-muted">
                    Лиги
                </h2>
                <p
                    v-if="soonHint"
                    class="animate-miq-fade text-xs font-medium text-miq-warn"
                    role="status"
                >
                    {{ soonHint }}
                </p>
            </div>

            <div
                class="animate-miq-stagger -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:-mx-0 sm:px-0"
                role="listbox"
                aria-label="Переключатель лиг"
            >
                <button
                    v-for="league in leagues"
                    :key="league.id"
                    type="button"
                    role="option"
                    class="miq-chip"
                    :class="{
                        'is-active': league.id === selectedLeague && league.status === 'live',
                        'is-soon': league.status === 'soon',
                    }"
                    :aria-selected="league.id === selectedLeague"
                    :aria-disabled="league.status === 'soon'"
                    @click="selectLeague(league)"
                >
                    <span class="whitespace-nowrap">
                        <span class="text-miq-muted">{{ league.country }}</span>
                        · {{ league.name }}
                    </span>
                    <span
                        v-if="league.status === 'soon'"
                        class="rounded px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-miq-muted"
                    >
                        Скоро
                    </span>
                </button>
            </div>
        </section>

        <template v-if="hasLiveLeagues">
            <section class="mt-6" aria-label="Период">
                <div
                    class="inline-flex rounded-md border border-miq-line/80 bg-white/50 p-0.5"
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

            <section class="mt-5" aria-label="Матчи" :key="listKey">
                <div
                    v-if="filteredMatches.length === 0"
                    class="animate-miq-fade border-y border-dashed border-miq-line py-10 text-center"
                >
                    <p class="font-medium text-miq-ink-soft">
                        На этот период матчей нет
                    </p>
                    <p class="mt-1 text-sm text-miq-muted">
                        Попробуйте другой период выше.
                    </p>
                </div>

                <ul v-else class="animate-miq-stagger -mx-1">
                    <li v-for="match in filteredMatches" :key="match.id">
                        <Link
                            :href="route('matches.show', match.id)"
                            class="miq-match-row px-1 py-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="miq-display text-base font-semibold leading-snug text-miq-ink sm:text-lg">
                                        {{ match.home }}
                                        <span class="font-medium text-miq-muted">—</span>
                                        {{ match.away }}
                                    </p>
                                    <p class="mt-1 text-sm text-miq-muted">
                                        {{ formatKickoff(match.kickoff_at) }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p
                                        v-if="scoreText(match)"
                                        class="font-display text-lg font-bold tabular-nums text-miq-ink"
                                    >
                                        {{ scoreText(match) }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs font-semibold uppercase tracking-wide"
                                        :class="{
                                            'text-miq-live': match.status === 'live',
                                            'text-miq-muted': match.status !== 'live',
                                        }"
                                    >
                                        {{ statusLabel(match.status) }}
                                    </p>
                                </div>
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>
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
