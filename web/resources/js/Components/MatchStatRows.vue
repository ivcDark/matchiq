<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** [{ id, title, column: left|right, rows: [{ id, label, home, away, fmt, better, hint }] }] */
    sections: { type: Array, required: true },
    home: { type: String, required: true },
    away: { type: String, required: true },
});

const columns = computed(() => ({
    left: props.sections.filter((s) => s.column !== 'right'),
    right: props.sections.filter((s) => s.column === 'right'),
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
