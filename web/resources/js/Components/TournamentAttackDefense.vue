<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    /** { best_attack|best_defense|worst_attack|worst_defense: [{ rank, team, goals, per_match }] } */
    data: { type: Object, required: true },
});

const perMatch = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

const TABLES = [
    { key: 'best_attack', title: 'Топ 5 лучших нападений', goalsLabel: 'Забитые голы', tone: 'good' },
    { key: 'best_defense', title: 'Топ 5 лучших защит', goalsLabel: 'Пропущенные голы', tone: 'good' },
    { key: 'worst_attack', title: 'Наихудшая атака', goalsLabel: 'Забитые голы', tone: 'bad' },
    { key: 'worst_defense', title: 'Наихудшая защита', goalsLabel: 'Пропущенные голы', tone: 'bad' },
];

const tables = computed(() =>
    TABLES.map((table) => ({
        ...table,
        rows: (props.data[table.key] ?? []).map((row) => ({ ...row, perMatchText: perMatch.format(row.per_match) })),
    })),
);
</script>

<template>
    <section class="miq-panel" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <div class="miq-tt-ad-grid">
            <table v-for="table in tables" :key="table.key" class="miq-tt-mini miq-tt-ad" :data-tone="table.tone">
                <thead>
                    <tr>
                        <th colspan="4">{{ table.title }}</th>
                    </tr>
                    <tr class="miq-tt-ad-sub">
                        <th class="is-rank">№</th>
                        <th class="is-team">Команда</th>
                        <th>{{ table.goalsLabel }}</th>
                        <th title="Голов на один матч">На матч</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in table.rows" :key="row.team">
                        <td class="is-rank">{{ row.rank }}</td>
                        <td class="is-team">
                            <span class="block truncate">{{ row.team }}</span>
                        </td>
                        <td class="is-goals">{{ row.goals }}</td>
                        <td class="is-per-match">{{ row.perMatchText }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
