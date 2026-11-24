<script setup>
import { dateRange, shortDate } from '@/lib/matchDates';

defineProps({
    title: { type: String, default: 'Расписание' },
    /** [{ round, date_from, date_to, is_next, matches: [{ home, away, date, time, prob_*?, top_market? }] }] */
    rounds: { type: Array, required: true },
});

const hasProbs = (m) =>
    typeof m.prob_home === 'number' && typeof m.prob_draw === 'number' && typeof m.prob_away === 'number';

function topKey(m) {
    const values = { home: m.prob_home, draw: m.prob_draw, away: m.prob_away };

    return Object.keys(values).sort((a, b) => values[b] - values[a])[0];
}
</script>

<template>
    <section class="miq-panel miq-tt-rounds" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">предстоящие туры · вероятности — у ближайшего</p>
        </div>

        <p v-if="!rounds.length" class="px-3.5 py-4 text-sm text-miq-muted">Все туры сыграны.</p>

        <details v-for="block in rounds" :key="block.round" class="miq-tt-round" :open="block.is_next">
            <summary class="miq-tt-round-head">
                <svg class="miq-tt-round-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7 5l6 5-6 5V5z" />
                </svg>
                <span class="miq-tt-round-title">{{ block.round }} тур</span>
                <span class="miq-tt-round-dates">{{ dateRange(block.date_from, block.date_to) }}</span>
                <span v-if="block.is_next" class="miq-tt-round-badge">Ближайший</span>
            </summary>

            <ul class="miq-tt-round-list">
                <li v-for="m in block.matches" :key="`${m.home}-${m.away}`" class="miq-tt-match">
                    <span class="miq-tt-match-when">
                        <span>{{ shortDate(m.date) }}</span>
                        <span class="miq-tt-match-time">{{ m.time }}</span>
                    </span>
                    <span class="miq-tt-match-pair">
                        <span class="miq-tt-match-team is-home">{{ m.home }}</span>
                        <span class="miq-tt-match-vs">—</span>
                        <span class="miq-tt-match-team is-away">{{ m.away }}</span>
                    </span>

                    <span v-if="hasProbs(m)" class="miq-tt-match-bets">
                        <span
                            class="miq-result-probs"
                            role="group"
                            :aria-label="`П1 ${m.prob_home}% · X ${m.prob_draw}% · П2 ${m.prob_away}%`"
                        >
                            <span class="miq-odds-chip is-home" :class="{ 'is-top': topKey(m) === 'home' }">
                                <span class="miq-odds-label">П1</span>
                                <span class="miq-odds-val">{{ m.prob_home }}%</span>
                            </span>
                            <span class="miq-odds-chip is-draw" :class="{ 'is-top': topKey(m) === 'draw' }">
                                <span class="miq-odds-label">X</span>
                                <span class="miq-odds-val">{{ m.prob_draw }}%</span>
                            </span>
                            <span class="miq-odds-chip is-away" :class="{ 'is-top': topKey(m) === 'away' }">
                                <span class="miq-odds-label">П2</span>
                                <span class="miq-odds-val">{{ m.prob_away }}%</span>
                            </span>
                        </span>
                        <span v-if="m.top_market" class="miq-market-chip">
                            <span class="miq-market-label">{{ m.top_market.label }}</span>
                            <span class="miq-market-dot" aria-hidden="true">·</span>
                            <span class="miq-odds-val">{{ m.top_market.prob }}%</span>
                        </span>
                    </span>
                </li>
            </ul>
        </details>
    </section>
</template>
