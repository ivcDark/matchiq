<script setup>
import { Link } from '@inertiajs/vue3';
import { dateRange, shortDate } from '@/lib/matchDates';

defineProps({
    title: { type: String, default: 'Результаты' },
    /** [{ round, date_from, date_to, matches: [{ id, home, away, home_goals, away_goals, date, time }] }] — от последнего тура к первому */
    rounds: { type: Array, required: true },
    /** Сколько последних туров раскрыто сразу. */
    openCount: { type: Number, default: 2 },
});
</script>

<template>
    <section class="miq-panel miq-tt-rounds" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">сыгранные туры · сначала последний</p>
        </div>

        <p v-if="!rounds.length" class="px-3.5 py-4 text-sm text-miq-muted">Сыгранных матчей пока нет.</p>

        <details v-for="(block, index) in rounds" :key="block.round" class="miq-tt-round" :open="index < openCount">
            <summary class="miq-tt-round-head">
                <svg class="miq-tt-round-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7 5l6 5-6 5V5z" />
                </svg>
                <span class="miq-tt-round-title">{{ block.round }} тур</span>
                <span class="miq-tt-round-dates">{{ dateRange(block.date_from, block.date_to) }}</span>
            </summary>

            <ul class="miq-tt-round-list">
                <li v-for="m in block.matches" :key="m.id" class="miq-tt-match">
                    <Link :href="route('matches.show', m.id)" class="miq-tt-match-link">
                        <span class="miq-tt-match-when">
                            <span>{{ shortDate(m.date) }}</span>
                            <span class="miq-tt-match-time">{{ m.time }}</span>
                        </span>
                        <span class="miq-tt-match-pair">
                            <span
                                class="miq-tt-match-team is-home"
                                :class="{ 'is-winner': m.home_goals > m.away_goals }"
                            >{{ m.home }}</span>
                            <span class="miq-tt-match-score" :aria-label="`Счёт ${m.home_goals}:${m.away_goals}`">
                                {{ m.home_goals }}<span aria-hidden="true">:</span>{{ m.away_goals }}
                            </span>
                            <span
                                class="miq-tt-match-team is-away"
                                :class="{ 'is-winner': m.away_goals > m.home_goals }"
                            >{{ m.away }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
        </details>
    </section>
</template>
