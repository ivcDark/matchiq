<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import { computed, ref } from 'vue';

const MEDALS = { 1: 'gold', 2: 'silver', 3: 'bronze' };

const props = defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    /**
     * [{ key, label, title?, format?(row), value?(row), hideMobile?, firstDir? }]
     * value — по чему сортировать (по умолчанию row[key]); firstDir — направление при первом клике.
     */
    columns: { type: Array, required: true },
    defaultSort: { type: Object, required: true },
    limit: { type: Number, default: 10 },
    emptyText: { type: String, default: 'Пока нет игроков, подходящих под условия' },
});

const sortKey = ref(props.defaultSort.key);
const sortDir = ref(props.defaultSort.dir ?? 'desc');

const sortColumn = computed(() => props.columns.find((c) => c.key === sortKey.value));

const visibleRows = computed(() => {
    const column = sortColumn.value;
    const valueOf = (row) => (column?.value ? column.value(row) : row[column?.key]);
    const factor = sortDir.value === 'asc' ? 1 : -1;

    return props.rows
        .map((row, index) => ({ row, index, value: valueOf(row) }))
        .sort((a, b) => (a.value - b.value) * factor || a.index - b.index)
        .slice(0, props.limit)
        .map((item) => item.row);
});

function sortBy(column) {
    if (sortKey.value === column.key) {
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
        return;
    }
    sortKey.value = column.key;
    sortDir.value = column.firstDir ?? 'desc';
}

function ariaSort(column) {
    if (sortKey.value !== column.key) return 'none';
    return sortDir.value === 'desc' ? 'descending' : 'ascending';
}

function cell(column, row) {
    return column.format ? column.format(row) : row[column.key];
}
</script>

<template>
    <section class="miq-panel miq-tt-card" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p v-if="hint" class="truncate text-right text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <div v-if="rows.length" class="overflow-x-auto">
            <table class="miq-tt-ptable">
                <thead>
                    <tr>
                        <th class="is-rank" title="Место в текущей сортировке">#</th>
                        <th class="is-player">Игрок</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="{ 'is-mobile-hidden': column.hideMobile, 'is-sorted': sortKey === column.key }"
                            :aria-sort="ariaSort(column)"
                        >
                            <button
                                type="button"
                                class="miq-tt-sort-btn"
                                :title="column.title || column.label"
                                @click="sortBy(column)"
                            >
                                {{ column.label }}
                                <svg
                                    class="miq-tt-sort-icon"
                                    :class="{
                                        'is-active': sortKey === column.key,
                                        'is-asc': sortKey === column.key && sortDir === 'asc',
                                    }"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M5 8l5 5 5-5" />
                                </svg>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in visibleRows" :key="row.name">
                        <td class="is-rank">
                            <span class="miq-tt-rank" :data-medal="MEDALS[i + 1]">{{ i + 1 }}</span>
                        </td>
                        <td class="is-player">
                            <div class="flex min-w-0 items-center gap-2">
                                <PlayerAvatar :name="row.name" size="sm" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-miq-ink">{{ row.name }}</p>
                                    <p class="truncate text-xs text-miq-muted">{{ row.team }}</p>
                                </div>
                            </div>
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="{ 'is-mobile-hidden': column.hideMobile, 'is-sorted': sortKey === column.key }"
                        >
                            {{ cell(column, row) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="px-3.5 py-5 text-sm text-miq-muted">{{ emptyText }}</p>
    </section>
</template>
