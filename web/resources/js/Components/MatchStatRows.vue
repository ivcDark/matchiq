<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    /**
     * { match, first_half, second_half } — каждый период: [{ id, title, column: left|right, rows: [{ id, label, home, away, fmt, better, hint }] }];
     * second_half = null, пока 2-й тайм не начался (live до перерыва)
     */
    stats: { type: Object, required: true },
    home: { type: String, required: true },
    away: { type: String, required: true },
});

const periods = computed(() => [
    { id: 'match', label: 'Весь матч', available: Boolean(props.stats.match) },
    { id: 'first_half', label: '1 тайм', available: Boolean(props.stats.first_half) },
    { id: 'second_half', label: '2 тайм', available: Boolean(props.stats.second_half) },
]);

const period = ref('match');

watch(
    () => props.stats,
    () => {
        if (!props.stats[period.value]) period.value = 'match';
    },
);

const sections = computed(() => props.stats[period.value] || []);

const columns = computed(() => ({
    left: sections.value.filter((s) => s.column !== 'right'),
    right: sections.value.filter((s) => s.column === 'right'),
}));

const dec = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function format(value, fmt) {
    if (value === null || value === undefined) return '–';
    if (fmt === 'pct') return `${value}%`;
    if (fmt === 'dec') return dec.format(value);
    if (fmt === 'signed') {
        const text = dec.format(Math.abs(value));
        if (value > 0) return `+${text}`;
        return value < 0 ? `−${text}` : text;
    }
    return String(value);
}

/** Возвращает 'home' | 'away' | null — кто лучше в строке (null при равенстве или нейтральной метрике). */
function leader(row) {
    if (!row.better || row.home == null || row.away == null || row.home === row.away) return null;
    const homeBigger = row.home > row.away;
    if (row.better === 'low') return homeBigger ? 'away' : 'home';
    return homeBigger ? 'home' : 'away';
}
</script>

<template>
    <section class="miq-panel miq-mc-stats" aria-label="Статистика матча">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Статистика матча</h2>
            <p class="text-[11px] font-medium text-miq-muted">лучшее значение выделено</p>
        </div>
        <div class="miq-mc-tabs" role="tablist" aria-label="Период статистики">
            <button
                v-for="p in periods"
                :key="p.id"
                type="button"
                role="tab"
                class="miq-mc-tab"
                :class="{ 'is-active': period === p.id }"
                :aria-selected="period === p.id"
                :disabled="!p.available"
                :title="p.available ? undefined : 'Тайм ещё не начался'"
                @click="period = p.id"
            >
                {{ p.label }}
            </button>
        </div>
        <div class="miq-mc-stats-head" aria-hidden="true">
            <span class="truncate text-left">{{ home }}</span>
            <span class="truncate text-right">{{ away }}</span>
        </div>

        <div class="miq-mc-stats-cols">
            <div v-for="(group, key) in columns" :key="key" class="miq-mc-stats-col">
                <div v-for="section in group" :key="section.id" class="miq-mc-stats-block">
                    <h3 class="miq-mc-stats-title">{{ section.title }}</h3>
                    <div
                        v-for="row in section.rows"
                        :key="row.id"
                        class="miq-mc-stat-row"
                        :title="row.hint || undefined"
                    >
                        <span class="miq-mc-stat-val is-home tabular-nums" :class="{ 'is-best': leader(row) === 'home' }">{{ format(row.home, row.fmt) }}</span>
                        <span class="miq-mc-stat-label">{{ row.label }}</span>
                        <span class="miq-mc-stat-val is-away tabular-nums" :class="{ 'is-best': leader(row) === 'away' }">{{ format(row.away, row.fmt) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
