<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import { computed, ref } from 'vue';

const POSITIONS = {
    GK: { short: 'ВР', full: 'Вратарь' },
    DF: { short: 'ЗЩ', full: 'Защитник' },
    MF: { short: 'ПЗ', full: 'Полузащитник' },
    FW: { short: 'НАП', full: 'Нападающий' },
};

const MEDALS = { 1: 'gold', 2: 'silver', 3: 'bronze' };

const props = defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    /**
     * [{ key, label, title?, format?(row), value?(row), hideMobile?, firstDir?, bar?, tone? }]
     * value — по чему сортировать (по умолчанию row[key]); firstDir — направление при первом клике;
     * bar — полоса в ячейке (доля от максимума по всему списку); tone — 'coral' вместо sage.
     */
    columns: { type: Array, required: true },
    defaultSort: { type: Object, required: true },
    limit: { type: Number, default: 10 },
    emptyText: { type: String, default: 'Пока нет игроков, подходящих под условия' },
});

const sortKey = ref(props.defaultSort.key);
const sortDir = ref(props.defaultSort.dir ?? 'desc');

const sortColumn = computed(() => props.columns.find((c) => c.key === sortKey.value));

function valueOf(column, row) {
    return column?.value ? column.value(row) : row[column?.key];
}

const barMax = computed(() => {
    const maxes = {};
    for (const column of props.columns.filter((c) => c.bar)) {
        maxes[column.key] = Math.max(0, ...props.rows.map((row) => Number(valueOf(column, row)) || 0));
    }
    return maxes;
});

function barWidth(column, row) {
    const max = barMax.value[column.key];
    const value = Number(valueOf(column, row)) || 0;
    if (!max || value <= 0) return '0%';
    return `${Math.max(6, Math.round((value / max) * 100))}%`;
}

const visibleRows = computed(() => {
    const column = sortColumn.value;
    const factor = sortDir.value === 'asc' ? 1 : -1;

    return props.rows
        .map((row, index) => ({ row, index, value: valueOf(column, row) }))
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
                        <th class="is-rank" title="Место в текущей сортировке">№</th>
                        <th class="is-player">Игрок</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="{
                                'is-mobile-hidden': column.hideMobile,
                                'is-sorted': sortKey === column.key,
                                'is-bar': column.bar,
                            }"
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
                            <div class="flex min-w-0 items-center gap-1.5">
                                <PlayerAvatar :name="row.name" size="sm" />
                                <p class="min-w-0 truncate text-[13px] font-semibold leading-tight text-miq-ink">
                                    {{ row.name }}
                                    <span
                                        v-if="POSITIONS[row.position]"
                                        class="miq-tt-pos"
                                        :data-pos="row.position"
                                        :title="POSITIONS[row.position].full"
                                    >{{ POSITIONS[row.position].short }}</span>
                                    <span class="miq-tt-team">{{ row.team }}</span>
                                </p>
                            </div>
                        </td>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="{
                                'is-mobile-hidden': column.hideMobile,
                                'is-sorted': sortKey === column.key,
                                'is-bar': column.bar,
                            }"
                        >
                            <div
                                v-if="column.bar"
                                class="miq-tt-bar"
                                :class="{ 'is-coral': column.tone === 'coral' }"
                            >
                                <span class="miq-tt-bar-fill" :style="{ width: barWidth(column, row) }" />
                                <span class="miq-tt-bar-num">{{ cell(column, row) }}</span>
                            </div>
                            <template v-else>{{ cell(column, row) }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="px-3.5 py-5 text-sm text-miq-muted">{{ emptyText }}</p>
    </section>
</template>
