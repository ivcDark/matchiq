<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** { position, teams, zone, zone_label, played, wins, draws, losses, gf, ga, gd, pts, form, behind_leader, neighbors, tournament, tournament_id } */
    standing: { type: Object, required: true },
    round: { type: Number, required: true },
});

const FORM_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };
const FORM_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };

const signed = (n) => (n > 0 ? `+${n}` : String(n).replace('-', '−'));

const kpis = computed(() => [
    { label: 'Игры', value: props.standing.played },
    { label: 'В–Н–П', value: `${props.standing.wins}–${props.standing.draws}–${props.standing.losses}` },
    { label: 'Голы', value: `${props.standing.gf}–${props.standing.ga}` },
    { label: '+/−', value: signed(props.standing.gd) },
    { label: 'Очки', value: props.standing.pts },
    {
        label: 'От лидера',
        value: props.standing.behind_leader === 0 ? 'лидер' : `−${props.standing.behind_leader}`,
    },
]);
</script>

<template>
    <section class="miq-panel" aria-label="Место в турнирной таблице">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ standing.tournament }} · таблица</h2>
            <Link
                :href="`${route('tournaments.show', standing.tournament_id)}#standings`"
                class="text-xs font-semibold text-miq-pitch hover:underline"
            >Вся таблица</Link>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3.5 py-2.5">
            <p class="flex items-baseline gap-1.5">
                <span class="num-display text-3xl leading-none text-miq-ink">{{ standing.position }}</span>
                <span class="text-sm text-miq-muted">место из {{ standing.teams }}</span>
            </p>
            <span
                v-if="standing.zone_label"
                class="miq-club-head-pos"
                :data-zone="standing.zone"
                style="border-left: 4px solid var(--tt-zone)"
            >{{ standing.zone_label }}</span>
            <span class="miq-table-form ml-auto" role="img" :aria-label="`Форма: ${standing.form.join(' ')}`">
                <span
                    v-for="(letter, i) in standing.form"
                    :key="i"
                    class="miq-form-chip"
                    :class="FORM_CLASSES[letter]"
                    :title="FORM_TITLES[letter]"
                >{{ letter }}</span>
            </span>
        </div>

        <dl class="miq-club-kpis" :aria-label="`После ${round} тура`">
            <div v-for="kpi in kpis" :key="kpi.label" class="miq-club-kpi">
                <dt>{{ kpi.label }}</dt>
                <dd>{{ kpi.value }}</dd>
            </div>
        </dl>

        <div class="overflow-x-auto">
            <table class="miq-club-table">
                <thead>
                    <tr>
                        <th class="is-left" title="Место">#</th>
                        <th class="is-left">Команда</th>
                        <th title="Игры">И</th>
                        <th title="Разница мячей">+/−</th>
                        <th title="Очки">Очки</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in standing.neighbors"
                        :key="row.team"
                        :class="{ 'is-current': row.is_current }"
                        :data-zone="row.zone || undefined"
                        :aria-current="row.is_current ? 'true' : undefined"
                    >
                        <td class="is-left">{{ row.pos }}</td>
                        <td class="is-left">
                            <span v-if="row.is_current" class="font-bold text-miq-ink">{{ row.team }}</span>
                            <Link
                                v-else-if="row.team_id"
                                :href="route('clubs.show', row.team_id)"
                                class="miq-club-link"
                            >{{ row.team }}</Link>
                            <template v-else>{{ row.team }}</template>
                        </td>
                        <td>{{ row.played }}</td>
                        <td>{{ signed(row.gd) }}</td>
                        <td class="is-strong">{{ row.pts }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
