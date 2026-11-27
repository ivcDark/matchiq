<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MatchProbTiles from '@/Components/MatchProbTiles.vue';
import TeamLogo from '@/Components/TeamLogo.vue';
import { bestMarkets } from '@/lib/matchProbs';

const props = defineProps({
    match: { type: Object, required: true },
    href: { type: String, required: true },
    leagueLabel: { type: String, default: '' },
    time: { type: String, default: '' },
});

const markets = computed(() => bestMarkets(props.match, 3));
const preview = computed(() => (typeof props.match.preview === 'string' ? props.match.preview.trim() : ''));
</script>

<template>
    <article class="miq-motd" aria-label="Матч дня">
        <div class="miq-motd-head">
            <span class="miq-motd-badge">Матч дня</span>
            <p class="truncate text-xs font-medium text-miq-ink-soft">
                <span v-if="leagueLabel">{{ leagueLabel }}</span>
                <span v-if="leagueLabel && time" class="mx-1 text-miq-muted" aria-hidden="true">·</span>
                <span v-if="time" class="tabular-nums">{{ time }}</span>
            </p>
        </div>

        <div class="miq-motd-body">
            <div class="miq-motd-teams miq-display">
                <span class="miq-motd-team">
                    <TeamLogo :src="match.home_logo" :name="match.home" :size="34" />
                    <span class="miq-motd-name">{{ match.home }}</span>
                </span>
                <span class="miq-motd-vs" aria-hidden="true">—</span>
                <span class="miq-motd-team miq-motd-team--away">
                    <span class="miq-motd-name">{{ match.away }}</span>
                    <TeamLogo :src="match.away_logo" :name="match.away" :size="34" />
                </span>
            </div>

            <MatchProbTiles :match="match" size="lg" />

            <div v-if="markets.length" class="miq-motd-markets" aria-label="Лучшие события">
                <span
                    v-for="m in markets"
                    :key="m.label"
                    class="miq-market-chip"
                    :title="m.market"
                >
                    <span class="miq-market-label">{{ m.label }}</span>
                    <span class="miq-market-dot" aria-hidden="true">·</span>
                    <span class="miq-odds-val">{{ m.prob }}%</span>
                </span>
            </div>

            <div class="miq-motd-foot">
                <p class="miq-motd-preview" :class="{ 'is-stub': !preview }">
                    <span class="miq-motd-ai">AI</span>
                    {{ preview || 'AI-превью появится вместе с прогнозом модели.' }}
                </p>
                <Link :href="href" class="miq-motd-cta">События</Link>
            </div>
        </div>
    </article>
</template>
