<script setup>
import TournamentContextTabs from '@/Components/TournamentContextTabs.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    title: { type: String, default: 'Турнирная таблица' },
    round: { type: Number, required: true },
    zones: { type: Array, default: () => [] },
    /** { overall|home|away: [{ pos, team, played, wins, draws, losses, gf, ga, gd, pts, form, zone }] } */
    tables: { type: Object, required: true },
    /** Показывать переключатель «Все / Хозяева / Гости». */
    switchable: { type: Boolean, default: false },
});

const FORM_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };
const FORM_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };
const HINTS = {
    overall: (round) => `после ${round} тура`,
    home: (round) => `только домашние матчи · после ${round} тура`,
    away: (round) => `только гостевые матчи · после ${round} тура`,
};

const context = ref('overall');

const current = computed(() => (props.switchable ? context.value : 'overall'));
const rows = computed(() => props.tables[current.value] ?? []);
const zoneLabels = computed(() => Object.fromEntries(props.zones.map((z) => [z.id, z.label])));

function signed(n) {
    return n > 0 ? `+${n}` : String(n).replace('-', '−');
}
</script>

<template>
    <section class="miq-panel miq-tt-standings" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">{{ HINTS[current](round) }}</p>
        </div>

        <TournamentContextTabs v-if="switchable" v-model="context" label="Матчи" overall-label="Все" />

        <div class="overflow-x-auto">
            <table class="miq-tt-table">
                <thead>
                    <tr>
                        <th class="is-rank" title="Место">#</th>
                        <th class="is-team">Команда</th>
                        <th title="Игры">И</th>
                        <th title="Победы">В</th>
                        <th title="Ничьи">Н</th>
                        <th title="Поражения">П</th>
                        <th title="Забитые голы">ЗГ</th>
                        <th title="Пропущенные голы">ПГ</th>
                        <th title="Разница мячей">+/−</th>
                        <th title="Очки">Очки</th>
                        <th class="is-form" title="Последние 5 матчей · слева старше, справа свежее">Форма</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.team" :data-zone="row.zone || undefined">
                        <td class="is-rank" :title="row.zone ? zoneLabels[row.zone] : undefined">{{ row.pos }}</td>
                        <td class="is-team">
                            <Link
                                v-if="row.team_id"
                                :href="route('clubs.show', row.team_id)"
                                class="miq-club-link block truncate"
                                :title="`${row.team} — страница клуба`"
                            >{{ row.team }}</Link>
                            <span v-else class="block truncate">{{ row.team }}</span>
                        </td>
                        <td>{{ row.played }}</td>
                        <td>{{ row.wins }}</td>
                        <td>{{ row.draws }}</td>
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

        <ul v-if="current === 'overall'" class="miq-tt-legend" aria-label="Легенда зон">
            <li v-for="zone in zones" :key="zone.id" :data-zone="zone.id">
                <span class="miq-tt-legend-dot" aria-hidden="true" />
                {{ zone.label }}
                <span class="text-miq-muted">· {{ zone.range }}</span>
            </li>
        </ul>
        <p v-else class="miq-tt-legend text-miq-muted">Зоны еврокубков и вылета показаны в таблице «Все».</p>
    </section>
</template>
