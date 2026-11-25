<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** { matches, scored, conceded, scored_per_match, conceded_per_match, markets: [{ key, title, left_label, right_label, left, right, left_pct, right_pct }] } */
    goals: { type: Object, required: true },
    hint: { type: String, default: '' },
});

const dec = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const perMatch = (n) => (n === null || n === undefined ? '—' : dec.format(n));

const totals = computed(() => props.goals.markets.filter((m) => m.key.startsWith('total_')));
const yesNo = computed(() => props.goals.markets.filter((m) => !m.key.startsWith('total_')));

function segment(market, side) {
    const pct = side === 'left' ? market.left_pct : market.right_pct;

    return { width: `${pct ?? 50}%` };
}
</script>

<template>
    <section class="miq-panel" aria-label="Голы забитые и пропущенные">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Голы</h2>
            <div class="miq-club-head-ctl">
                <slot name="controls" />
                <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
            </div>
        </div>

        <div class="miq-club-split-total">
            <div class="text-left">
                <p class="miq-club-split-num">{{ goals.scored }}</p>
                <p class="mt-0.5 text-[11px] text-miq-muted">забито</p>
            </div>
            <div class="text-center">
                <p class="miq-club-split-pair" role="img" :aria-label="`В среднем за матч: ${perMatch(goals.scored_per_match)} забито, ${perMatch(goals.conceded_per_match)} пропущено`">
                    <span style="background: var(--miq-cmp-lead)">{{ perMatch(goals.scored_per_match) }}</span>
                    <span style="background: var(--miq-cmp-trail)">{{ perMatch(goals.conceded_per_match) }}</span>
                </p>
                <p class="mt-0.5 text-[11px] text-miq-muted">за матч · {{ goals.matches }} игр</p>
            </div>
            <div class="text-right">
                <p class="miq-club-split-num">{{ goals.conceded }}</p>
                <p class="mt-0.5 text-[11px] text-miq-muted">пропущено</p>
            </div>
        </div>

        <template v-if="goals.matches > 0">
            <p class="miq-club-best-title">Тотал матча: менее / более</p>
            <div v-for="market in totals" :key="market.key" class="miq-club-split-row">
                <div class="miq-club-split-meta">
                    <span>{{ market.left_label }} <strong>{{ market.left }}</strong></span>
                    <span class="miq-club-split-title">Тотал {{ market.title }}</span>
                    <span>{{ market.right_label }} <strong>{{ market.right }}</strong></span>
                </div>
                <div class="miq-club-split-bar" role="img" :aria-label="`Тотал ${market.title}: менее ${market.left_pct}%, более ${market.right_pct}%`">
                    <span class="miq-club-split-seg is-left" :style="segment(market, 'left')">{{ market.left_pct }}%</span>
                    <span class="miq-club-split-seg is-right" :style="segment(market, 'right')">{{ market.right_pct }}%</span>
                </div>
            </div>

            <p class="miq-club-best-title">Да / нет</p>
            <div v-for="market in yesNo" :key="market.key" class="miq-club-split-row">
                <div class="miq-club-split-meta">
                    <span>{{ market.left_label }} <strong>{{ market.left }}</strong></span>
                    <span class="miq-club-split-title">{{ market.title }}</span>
                    <span>{{ market.right_label }} <strong>{{ market.right }}</strong></span>
                </div>
                <div class="miq-club-split-bar" role="img" :aria-label="`${market.title}: да ${market.left_pct}%, нет ${market.right_pct}%`">
                    <span class="miq-club-split-seg is-left" :style="segment(market, 'left')">{{ market.left_pct }}%</span>
                    <span class="miq-club-split-seg is-right" :style="segment(market, 'right')">{{ market.right_pct }}%</span>
                </div>
            </div>
        </template>
        <p v-else class="px-3.5 py-4 text-sm text-miq-muted">Сыгранных матчей в этом срезе нет.</p>
    </section>
</template>
