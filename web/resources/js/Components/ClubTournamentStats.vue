<script setup>
defineProps({
    /** { rows: [...], total: {...} } — показатели клуба по турнирам для выбранного контекста */
    stats: { type: Object, required: true },
    hint: { type: String, default: '' },
});

const nf = (digits) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const one = nf(1);
const two = nf(2);

const int = nf(0);

function fmt(value, formatter = one) {
    return value === null || value === undefined ? null : formatter.format(value);
}

function goals(row) {
    return row.penalty_goals > 0 ? `${row.goals} (${row.penalty_goals})` : String(row.goals);
}
</script>

<template>
    <section class="miq-panel" aria-label="Статистика по турнирам">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Статистика по турнирам</h2>
            <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="miq-club-table min-w-[40rem]">
                <thead>
                    <tr>
                        <th class="is-left is-sticky">Турнир</th>
                        <th title="Матчи">И</th>
                        <th title="Забитые голы (в скобках — с пенальти)">ЗГ</th>
                        <th title="Пропущенные голы">ПГ</th>
                        <th title="Удары за матч">Удары</th>
                        <th title="Жёлтые карточки">ЖК</th>
                        <th title="Красные карточки">КК</th>
                        <th title="Владение мячом, %">Влад.%</th>
                        <th title="Точность передач, %">Пас%</th>
                        <th title="Минуты на поле: сумма длительностей сыгранных матчей">Мин</th>
                        <th title="Средняя оценка">Рейт.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in stats.rows" :key="row.key">
                        <td class="is-left is-sticky font-semibold text-miq-ink">{{ row.name }}</td>
                        <td>{{ row.apps }}</td>
                        <td class="is-good">{{ goals(row) }}</td>
                        <td class="is-bad">{{ row.conceded }}</td>
                        <td :class="{ 'is-na': row.shots === null }">{{ fmt(row.shots) ?? '—' }}</td>
                        <td>{{ row.yellow }}</td>
                        <td>{{ row.red }}</td>
                        <td :class="{ 'is-na': row.possession === null }">{{ fmt(row.possession) ?? '—' }}</td>
                        <td :class="{ 'is-na': row.passing === null }">{{ fmt(row.passing) ?? '—' }}</td>
                        <td :class="{ 'is-na': row.minutes === null }">{{ fmt(row.minutes, int) ?? '—' }}</td>
                        <td :class="row.rating === null ? 'is-na' : 'miq-club-rating'">{{ fmt(row.rating, two) ?? '—' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="is-left is-sticky">{{ stats.total.name }}</td>
                        <td>{{ stats.total.apps }}</td>
                        <td>{{ goals(stats.total) }}</td>
                        <td>{{ stats.total.conceded }}</td>
                        <td>{{ fmt(stats.total.shots) ?? '—' }}</td>
                        <td>{{ stats.total.yellow }}</td>
                        <td>{{ stats.total.red }}</td>
                        <td>{{ fmt(stats.total.possession) ?? '—' }}</td>
                        <td>{{ fmt(stats.total.passing) ?? '—' }}</td>
                        <td>{{ fmt(stats.total.minutes, int) ?? '—' }}</td>
                        <td class="miq-club-rating">{{ fmt(stats.total.rating, two) ?? '—' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</template>
