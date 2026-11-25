<script setup>
import { computed } from 'vue';

const LIMIT = 5;

const props = defineProps({
    /** Игроки среза (турнир × матчи): { name, position, apps, minutes, goals, assists, yellow, red, aerial, rating } */
    players: { type: Array, required: true },
    hint: { type: String, default: '' },
});

const dec = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const dec2 = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const maxApps = computed(() => Math.max(1, ...props.players.map((p) => p.apps)));
const maxMinutes = computed(() => Math.max(1, ...props.players.map((p) => p.minutes)));

function top(list, compare) {
    return [...list].sort(compare).slice(0, LIMIT);
}

const blocks = computed(() => {
    const players = props.players;
    const regular = players.filter((p) => p.apps >= Math.max(1, Math.ceil(maxApps.value * 0.3)));
    const fieldPlayers = players.filter((p) => p.position !== 'GK' && p.aerial !== null && p.minutes >= maxMinutes.value * 0.2);

    return [
        {
            key: 'goals',
            title: 'Голы',
            kind: 'bar',
            rows: top(players.filter((p) => p.goals > 0), (a, b) => b.goals - a.goals || a.minutes - b.minutes),
            value: (p) => p.goals,
            label: (p) => String(p.goals),
        },
        {
            key: 'assists',
            title: 'Голевые передачи',
            kind: 'bar',
            rows: top(players.filter((p) => p.assists > 0), (a, b) => b.assists - a.assists || a.minutes - b.minutes),
            value: (p) => p.assists,
            label: (p) => String(p.assists),
        },
        {
            key: 'aerial',
            title: 'Борьба в воздухе (за матч)',
            kind: 'bar',
            rows: top(fieldPlayers, (a, b) => b.aerial - a.aerial),
            value: (p) => p.aerial,
            label: (p) => dec.format(p.aerial),
        },
        {
            key: 'rating',
            title: 'Рейтинг',
            kind: 'pill-coral',
            rows: top(regular.filter((p) => p.rating !== null), (a, b) => b.rating - a.rating || b.minutes - a.minutes),
            label: (p) => dec2.format(p.rating),
        },
        {
            key: 'aggression',
            title: 'Агрессивность (ЖК / КК)',
            kind: 'cards',
            rows: top(
                players.filter((p) => p.yellow + p.red > 0),
                (a, b) => b.red - a.red || b.yellow - a.yellow || a.minutes - b.minutes,
            ),
        },
        {
            key: 'apps',
            title: 'Выступления',
            kind: 'pill-muted',
            rows: top(players, (a, b) => b.apps - a.apps || b.minutes - a.minutes),
            label: (p) => String(p.apps),
        },
    ];
});

function barWidth(block, row) {
    const max = Math.max(...block.rows.map((r) => block.value(r)), 0.0001);

    return `${Math.max(14, Math.round((block.value(row) / max) * 100))}%`;
}
</script>

<template>
    <section class="miq-panel" aria-label="Лучшие игроки клуба">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Лучшие игроки</h2>
            <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <div class="miq-club-best-grid">
            <div v-for="block in blocks" :key="block.key" class="miq-club-best-block">
                <h3 class="miq-club-best-title">{{ block.title }}</h3>
                <ol v-if="block.rows.length">
                    <li v-for="row in block.rows" :key="row.name" class="miq-club-best-row">
                        <span class="truncate font-medium text-miq-ink" :title="row.name">{{ row.name }}</span>

                        <span v-if="block.kind === 'bar'" class="miq-club-bar" :class="{ 'is-coral': block.key === 'assists' }">
                            <span class="miq-club-bar-fill" :style="{ width: barWidth(block, row) }" />
                            <span class="miq-club-bar-num">{{ block.label(row) }}</span>
                        </span>
                        <span v-else-if="block.kind === 'cards'" class="flex justify-end gap-1" :aria-label="`ЖК ${row.yellow}, КК ${row.red}`">
                            <span class="miq-tt-card-chip is-yellow !ml-0">{{ row.yellow }}</span>
                            <span class="miq-tt-card-chip is-red !ml-0">{{ row.red }}</span>
                        </span>
                        <span v-else class="flex justify-end">
                            <span class="miq-club-pill" :class="block.kind === 'pill-coral' ? 'is-coral' : 'is-muted'">{{ block.label(row) }}</span>
                        </span>
                    </li>
                </ol>
                <p v-else class="px-3.5 py-3 text-xs text-miq-muted">Нет данных в этом срезе.</p>
            </div>
        </div>
    </section>
</template>
