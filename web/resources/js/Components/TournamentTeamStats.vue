<script setup>
import TournamentContextTabs from '@/Components/TournamentContextTabs.vue';
import { computed, ref } from 'vue';

const nf = (digits) =>
    new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const NF = { 0: nf(0), 1: nf(1), 2: nf(2) };

const props = defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    /** { overall|home|away: { possession|aggression|aerial|shots|passing|rating: [{ rank, team, ... }] } } */
    stats: { type: Object, required: true },
});

const context = ref('overall');

/**
 * scale — по какому значению считать 100% полосы: число или 'max' (максимум в таблице).
 */
const TABLES = [
    { key: 'possession', title: 'Владение мячом', field: 'possession', scale: 100, digits: 1, suffix: '%' },
    { key: 'aggression', title: 'Агрессивность', cards: true },
    { key: 'aerial', title: 'Выигранная борьба в воздухе', field: 'aerial', scale: 100, digits: 1, suffix: '%' },
    { key: 'shots', title: 'Удары за игру', field: 'shots', scale: 'max', digits: 1, suffix: '', tone: 'coral' },
    { key: 'passing', title: 'Точность передач', field: 'passing', scale: 100, digits: 1, suffix: '%' },
    { key: 'rating', title: 'Рейтинги команд', field: 'rating', scale: 10, digits: 2, suffix: '', tone: 'coral' },
];

const tables = computed(() =>
    TABLES.map((table) => {
        const rows = props.stats[context.value]?.[table.key] ?? [];
        const max = table.field ? Math.max(0, ...rows.map((r) => r[table.field])) : 0;
        const scale = table.scale === 'max' ? max : table.scale;

        return {
            ...table,
            rows: rows.map((row) => ({
                ...row,
                width: table.field && scale ? `${Math.max(6, Math.round((row[table.field] / scale) * 100))}%` : null,
                text: table.field ? `${NF[table.digits].format(row[table.field])}${table.suffix}` : null,
            })),
        };
    }),
);
</script>

<template>
    <section class="miq-panel miq-tt-teams" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <TournamentContextTabs v-model="context" />

        <div class="miq-tt-mini-grid">
            <table v-for="table in tables" :key="table.key" class="miq-tt-mini">
                <thead>
                    <tr>
                        <th colspan="2">{{ table.title }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in table.rows" :key="row.team">
                        <td class="is-team">
                            <span class="block truncate">{{ row.team }}</span>
                        </td>
                        <td v-if="table.cards" class="is-cards">
                            <span class="miq-tt-card-chip is-yellow" title="Жёлтые карточки">{{ row.yellow }}</span>
                            <span class="miq-tt-card-chip is-red" title="Красные карточки">{{ row.red }}</span>
                        </td>
                        <td v-else class="is-bar">
                            <div class="miq-tt-bar" :class="{ 'is-coral': table.tone === 'coral' }">
                                <span class="miq-tt-bar-fill" :style="{ width: row.width }" />
                                <span class="miq-tt-bar-num">{{ row.text }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
