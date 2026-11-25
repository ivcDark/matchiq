<script setup>
import { dayMonth } from '@/lib/matchDates';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** { name, path, stage, group, place, played, rounds_played, rounds_total, wins, draws, losses, gf, ga, pts, advance_places, in_playoff_zone, table, next } */
    cup: { type: Object, required: true },
    team: { type: String, required: true },
});

const verdict = computed(() =>
    props.cup.in_playoff_zone
        ? `В зоне выхода в плей-офф (${props.cup.advance_places} лучших из группы)`
        : `Вне зоны плей-офф: выходят ${props.cup.advance_places} лучших из группы`,
);

const signed = (n) => (n > 0 ? `+${n}` : String(n).replace('-', '−'));
</script>

<template>
    <section class="miq-panel" aria-label="Кубок России">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ cup.name }}</h2>
            <p class="text-xs text-miq-muted">{{ cup.path }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-2.5">
            <p class="flex items-baseline gap-1.5">
                <span class="num-display text-3xl leading-none text-miq-ink">{{ cup.place }}</span>
                <span class="text-sm text-miq-muted">место в группе {{ cup.group }}</span>
            </p>
            <span class="miq-club-head-pos">{{ cup.stage }}</span>
            <p class="w-full text-xs" :class="cup.in_playoff_zone ? 'text-miq-pitch' : 'text-miq-muted'">
                {{ verdict }} · сыграно {{ cup.rounds_played }} из {{ cup.rounds_total }} туров
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="miq-club-table">
                <thead>
                    <tr>
                        <th class="is-left" title="Место в группе">#</th>
                        <th class="is-left">Группа {{ cup.group }}</th>
                        <th title="Игры">И</th>
                        <th title="Победы">В</th>
                        <th title="Ничьи">Н</th>
                        <th title="Поражения">П</th>
                        <th title="Разница мячей">+/−</th>
                        <th title="Очки">Очки</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in cup.table"
                        :key="row.team"
                        :class="{ 'is-current': row.team === team, 'is-advance': row.pos <= cup.advance_places }"
                    >
                        <td class="is-left">{{ row.pos }}</td>
                        <td class="is-left">
                            <span v-if="row.team === team" class="font-bold text-miq-ink">{{ row.team }}</span>
                            <Link v-else-if="row.team_id" :href="route('clubs.show', row.team_id)" class="miq-club-link">{{ row.team }}</Link>
                            <template v-else>{{ row.team }}</template>
                        </td>
                        <td>{{ row.played }}</td>
                        <td>{{ row.wins }}</td>
                        <td>{{ row.draws }}</td>
                        <td>{{ row.losses }}</td>
                        <td>{{ signed(row.gd) }}</td>
                        <td class="is-strong">{{ row.pts }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="cup.next" class="border-t px-3.5 py-2 text-xs text-miq-ink-soft" style="border-color: color-mix(in srgb, var(--miq-line) 60%, transparent)">
            <span class="font-semibold uppercase tracking-wider text-miq-muted">Далее</span>
            · {{ cup.next.round }}-й тур · {{ dayMonth(cup.next.date) }} ·
            {{ cup.next.venue === 'home' ? 'дома' : 'в гостях' }} против
            <Link v-if="cup.next.opponent_id" :href="route('clubs.show', cup.next.opponent_id)" class="miq-club-link">{{ cup.next.opponent }}</Link>
            <template v-else>{{ cup.next.opponent }}</template>
        </p>
    </section>
</template>
