<script setup>
import { computed, ref } from 'vue';

const POSITIONS = {
    GK: { short: 'ВР', full: 'Вратарь' },
    DF: { short: 'ЗЩ', full: 'Защитник' },
    MF: { short: 'ПЗ', full: 'Полузащитник' },
    FW: { short: 'НАП', full: 'Нападающий' },
};

const props = defineProps({
    /** { name, position, age, height, weight, apps, starts, subs, minutes, goals, assists, yellow, red, shots, passing, aerial, mom, rating } */
    players: { type: Array, required: true },
    hint: { type: String, default: '' },
});

const COLUMNS = [
    { key: 'height', label: 'см', title: 'Рост, см', hideMobile: true },
    { key: 'weight', label: 'кг', title: 'Вес, кг', hideMobile: true },
    { key: 'apps', label: 'Выст', title: 'Матчей: в старте (и выходов на замену)', cell: (p) => (p.subs > 0 ? `${p.starts}(${p.subs})` : String(p.starts)) },
    { key: 'minutes', label: 'Мин', title: 'Сыграно минут' },
    { key: 'goals', label: 'Г', title: 'Голы', zero: true, good: true },
    { key: 'assists', label: 'А', title: 'Голевые передачи', zero: true, good: true },
    { key: 'yellow', label: 'ЖК', title: 'Жёлтые карточки', zero: true },
    { key: 'red', label: 'КК', title: 'Красные карточки', zero: true },
    { key: 'shots', label: 'Уд', title: 'Удары за матч', digits: 1 },
    { key: 'passing', label: 'Пас%', title: 'Точность передач, %', digits: 1 },
    { key: 'aerial', label: 'Возд.', title: 'Выигранные единоборства в воздухе за матч', digits: 1 },
    { key: 'mom', label: 'ЛИМ', title: 'Раз признан лучшим игроком матча', zero: true },
    { key: 'rating', label: 'Рейт.', title: 'Средняя оценка', digits: 2, rating: true },
];

const sortKey = ref('rating');
const sortDir = ref('desc');

const rows = computed(() => {
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const key = sortKey.value;

    return [...props.players].sort((a, b) => {
        const av = a[key] ?? -Infinity;
        const bv = b[key] ?? -Infinity;

        return av === bv ? b.minutes - a.minutes : (av > bv ? 1 : -1) * dir;
    });
});

function sortBy(column) {
    if (sortKey.value === column.key) {
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';

        return;
    }
    sortKey.value = column.key;
    sortDir.value = 'desc';
}

const nf = (d) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d });
const formatters = { 1: nf(1), 2: nf(2) };

function cell(column, player) {
    if (column.cell) {
        return column.cell(player);
    }
    const value = player[column.key];
    if (value === null || value === undefined || (column.zero && value === 0)) {
        return '–';
    }

    return column.digits ? formatters[column.digits].format(value) : String(value);
}

const ariaSort = (column) => (sortKey.value === column.key ? (sortDir.value === 'asc' ? 'ascending' : 'descending') : 'none');
</script>

<template>
    <section class="miq-panel" aria-label="Игроки клуба">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Игроки</h2>
            <div class="miq-club-head-ctl">
                <slot name="controls" />
                <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
            </div>
        </div>

        <div v-if="rows.length" class="overflow-x-auto">
            <table class="miq-club-table min-w-[44rem]">
                <thead>
                    <tr>
                        <th class="is-left is-sticky">Игрок</th>
                        <th
                            v-for="column in COLUMNS"
                            :key="column.key"
                            :class="{ 'is-sorted': sortKey === column.key, 'max-sm:hidden': column.hideMobile }"
                            :aria-sort="ariaSort(column)"
                        >
                            <button type="button" class="miq-club-sort" :title="column.title" @click="sortBy(column)">
                                {{ column.label }}
                                <svg
                                    class="miq-club-sort-icon"
                                    :class="{ 'is-active': sortKey === column.key, 'is-asc': sortKey === column.key && sortDir === 'asc' }"
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
                    <tr v-for="player in rows" :key="player.name">
                        <td class="is-left is-sticky">
                            <span class="font-semibold text-miq-ink">{{ player.name }}</span>
                            <span class="ml-1 text-[11px] text-miq-muted">{{ player.age }}</span>
                            <span
                                v-if="player.position"
                                class="miq-tt-pos"
                                :data-pos="player.position"
                                :title="POSITIONS[player.position]?.full"
                            >{{ POSITIONS[player.position]?.short }}</span>
                        </td>
                        <td
                            v-for="column in COLUMNS"
                            :key="column.key"
                            :class="{
                                'miq-club-rating': column.rating && player.rating !== null,
                                'is-strong': sortKey === column.key && !column.rating && !column.good,
                                'is-good': column.good,
                                'max-sm:hidden': column.hideMobile,
                            }"
                        >{{ cell(column, player) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="px-3.5 py-5 text-sm text-miq-muted">В этом срезе игроков нет.</p>
    </section>
</template>
