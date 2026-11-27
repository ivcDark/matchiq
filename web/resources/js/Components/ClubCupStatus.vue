<script setup>
import { dayMonth } from '@/lib/matchDates';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** { name, competition_id, stage, status, status_label, group, tie, next } */
    cup: { type: Object, required: true },
    team: { type: String, required: true },
});

const STATUS_CLASSES = {
    winner: 'text-miq-pitch',
    advanced: 'text-miq-pitch',
    group: 'text-miq-ink-soft',
    in_play: 'text-miq-ink-soft',
    eliminated: 'text-miq-muted',
    registered: 'text-miq-muted',
};

const signed = (n) => (n > 0 ? `+${n}` : String(n).replace('-', '−'));
const group = computed(() => props.cup.group);
const tie = computed(() => props.cup.tie);
const tieScore = computed(() => {
    if (!tie.value?.aggregate) {
        return null;
    }

    return tie.value.penalties ? `${tie.value.aggregate} (пен. ${tie.value.penalties})` : tie.value.aggregate;
});
</script>

<template>
    <section class="miq-panel" :aria-label="cup.name">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ cup.name }}</h2>
            <Link
                v-if="cup.competition_id"
                :href="route('tournaments.show', cup.competition_id)"
                class="text-xs font-semibold text-miq-pitch hover:underline"
            >О турнире</Link>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-2.5">
            <p v-if="group?.place" class="flex items-baseline gap-1.5">
                <span class="num-display text-3xl leading-none text-miq-ink">{{ group.place }}</span>
                <span class="text-sm text-miq-muted">место из {{ group.teams }} · {{ group.name }}</span>
            </p>
            <span class="miq-club-head-pos" :data-zone="group?.zone || undefined">{{ cup.status_label }}</span>
            <p v-if="group?.zone_label" class="w-full text-xs" :class="STATUS_CLASSES[cup.status]">{{ group.zone_label }}</p>
            <p v-if="tie" class="w-full text-xs" :class="STATUS_CLASSES[cup.status]">
                {{ tie.round }} · против
                <Link v-if="tie.opponent_id" :href="route('clubs.show', tie.opponent_id)" class="miq-club-link">{{ tie.opponent }}</Link>
                <template v-else>{{ tie.opponent }}</template>
                <template v-if="tieScore"> · {{ tieScore }}</template>
            </p>
            <p v-if="!group?.place && !tie" class="w-full text-xs text-miq-muted">Таблицы и сетки турнира появятся после первых матчей.</p>
        </div>

        <div v-if="group?.table?.length" class="overflow-x-auto">
            <table class="miq-club-table">
                <thead>
                    <tr>
                        <th class="is-left" title="Место в группе">#</th>
                        <th class="is-left">{{ group.name }}</th>
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
                        v-for="row in group.table"
                        :key="row.team_id"
                        :class="{ 'is-current': row.is_current }"
                        :data-zone="row.zone || undefined"
                        :aria-current="row.is_current ? 'true' : undefined"
                    >
                        <td class="is-left">{{ row.pos }}</td>
                        <td class="is-left">
                            <span v-if="row.is_current" class="font-bold text-miq-ink">{{ row.team }}</span>
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
            <template v-if="cup.next.stage"> · {{ cup.next.stage }}</template>
            · {{ dayMonth(cup.next.date) }} ·
            {{ cup.next.venue === 'home' ? 'дома' : 'в гостях' }} против
            <Link v-if="cup.next.opponent_id" :href="route('clubs.show', cup.next.opponent_id)" class="miq-club-link">{{ cup.next.opponent }}</Link>
            <template v-else>{{ cup.next.opponent }}</template>
        </p>
    </section>
</template>
