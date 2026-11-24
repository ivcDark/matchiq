<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';

const MEDALS = { 1: 'gold', 2: 'silver', 3: 'bronze' };

defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    /** row → { main: string, unit: string, meta: string } */
    format: { type: Function, required: true },
    emptyText: { type: String, default: 'Пока нет игроков, подходящих под условия' },
});
</script>

<template>
    <section class="miq-panel miq-tt-card" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p v-if="hint" class="truncate text-right text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <ol v-if="rows.length" class="miq-tt-list">
            <li v-for="row in rows" :key="`${row.rank}-${row.name}`" class="miq-tt-item">
                <span
                    class="miq-tt-rank"
                    :data-medal="MEDALS[row.rank]"
                >{{ row.rank }}</span>
                <PlayerAvatar :name="row.name" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-miq-ink">{{ row.name }}</p>
                    <p class="truncate text-xs text-miq-muted">
                        {{ row.team }}<template v-if="format(row).meta"> · {{ format(row).meta }}</template>
                    </p>
                </div>
                <div class="miq-tt-main">
                    <span class="num-display">{{ format(row).main }}</span>
                    <span class="miq-tt-unit">{{ format(row).unit }}</span>
                </div>
            </li>
        </ol>
        <p v-else class="px-3.5 py-5 text-sm text-miq-muted">{{ emptyText }}</p>
    </section>
</template>
