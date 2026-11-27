<script setup>
import TournamentContextTabs from '@/Components/TournamentContextTabs.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    title: { type: String, default: 'Групповой этап' },
    round: { type: Number, default: 0 },
    /** { name, rules: { win, draw, loss, penalty_win, penalty_loss }, groups: [{ id, name }] } */
    stage: { type: Object, required: true },
    /** { overall|home|away: [{ group_id, pos, team, team_id, played, wins, draws, losses, pen_wins, pen_losses, gf, ga, gd, pts, form }] } */
    tables: { type: Object, required: true },
    /** Показывать переключатель «Все / Хозяева / Гости». */
    switchable: { type: Boolean, default: false },
});

const FORM_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };
const FORM_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };
const HINTS = {
    overall: 'очки по правилам этапа',
    home: 'только домашние матчи · очки по правилам этапа',
    away: 'только гостевые матчи · очки по правилам этапа',
};

const context = ref('overall');
const current = computed(() => (props.switchable ? context.value : 'overall'));
const rows = computed(() => props.tables[current.value] ?? []);

const rules = computed(() => props.stage.rules ?? {});
const hasPens = computed(() => rules.value.penalty_win != null && rules.value.penalty_loss != null);
const allRows = computed(() => props.tables.overall ?? []);
const showDraws = computed(() => !hasPens.value || allRows.value.some((r) => r.draws > 0));

const groups = computed(() => props.stage.groups.map((group) => ({
    ...group,
    rows: rows.value.filter((r) => r.group_id === group.id),
})));

const rulesText = computed(() => {
    const r = rules.value;
    const parts = [`победа ${r.win}`];
    if (hasPens.value) {
        parts.push(`победа по пенальти ${r.penalty_win}`, `поражение по пенальти ${r.penalty_loss}`);
    } else {
        parts.push(`ничья ${r.draw}`);
    }
    parts.push(`поражение ${r.loss}`);
    return parts.join(' · ');
});

function signed(n) {
    return n > 0 ? `+${n}` : String(n).replace('-', '−');
}
</script>

<template>
    <section class="miq-panel miq-tt-standings miq-tt-groups" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">{{ HINTS[current] }}</p>
        </div>

        <TournamentContextTabs v-if="switchable" v-model="context" label="Матчи" overall-label="Все" />

        <div v-for="group in groups" :key="group.id" class="miq-tt-group" :data-group="group.name">
            <h3 class="miq-tt-group-title">{{ group.name }}</h3>

            <div class="overflow-x-auto">
                <table class="miq-tt-table">
                    <thead>
                        <tr>
                            <th class="is-rank" title="Место в группе">#</th>
                            <th class="is-team">Команда</th>
                            <th title="Игры">И</th>
                            <th title="Победы в основное время">В</th>
                            <th v-if="hasPens" title="Победы по пенальти">ВП</th>
                            <th v-if="showDraws" title="Ничьи">Н</th>
                            <th v-if="hasPens" title="Поражения по пенальти">ПП</th>
                            <th title="Поражения в основное время">П</th>
                            <th title="Забитые голы">ЗГ</th>
                            <th title="Пропущенные голы">ПГ</th>
                            <th title="Разница мячей">+/−</th>
                            <th title="Очки">Очки</th>
                            <th class="is-form" title="Последние 5 матчей · слева старше, справа свежее">Форма</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in group.rows" :key="row.team_id">
                            <td class="is-rank">{{ row.pos }}</td>
                            <td class="is-team">
                                <Link
                                    :href="route('clubs.show', row.team_id)"
                                    class="miq-club-link block truncate"
                                    :title="`${row.team} — страница клуба`"
                                >{{ row.team }}</Link>
                            </td>
                            <td>{{ row.played }}</td>
                            <td>{{ row.wins }}</td>
                            <td v-if="hasPens">{{ row.pen_wins }}</td>
                            <td v-if="showDraws">{{ row.draws }}</td>
                            <td v-if="hasPens">{{ row.pen_losses }}</td>
                            <td>{{ row.losses }}</td>
                            <td>{{ row.gf }}</td>
                            <td>{{ row.ga }}</td>
                            <td>{{ signed(row.gd) }}</td>
                            <td class="is-pts">{{ row.pts }}</td>
                            <td class="is-form">
                                <span class="miq-table-form" role="img" :aria-label="`Форма: ${row.form.join(' ')}`">
                                    <span
                                        v-for="(letter, i) in row.form"
                                        :key="i"
                                        class="miq-form-chip"
                                        :class="FORM_CLASSES[letter]"
                                        :title="FORM_TITLES[letter]"
                                    >{{ letter }}</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="miq-tt-legend text-miq-muted" aria-label="Очки за матч">
            Очки за матч: {{ rulesText }}.
            <template v-if="hasPens">ВП / ПП — ничья в основное время, решённая серией пенальти.</template>
        </p>
    </section>
</template>
