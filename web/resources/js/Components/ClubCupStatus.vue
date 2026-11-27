<script setup>
import { dayMonth } from '@/lib/matchDates';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** { name, competition_id, stage, status, status_label, group, knockout, tie, next } */
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
const knockout = computed(() => props.cup.knockout);
const tie = computed(() => knockout.value?.tie ?? props.cup.tie);
const tieScore = computed(() => {
    if (!tie.value?.aggregate) {
        return null;
    }

    return tie.value.penalties ? `${tie.value.aggregate} (пен. ${tie.value.penalties})` : tie.value.aggregate;
});

const rules = computed(() => group.value?.rules ?? null);
const hasPens = computed(() => rules.value?.penalty_win != null && rules.value?.penalty_loss != null);
const showDraws = computed(() => !hasPens.value || (group.value?.table ?? []).some((r) => r.draws > 0));
const hasBoth = computed(() => !!group.value && !!knockout.value);
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

        <section v-if="group" class="miq-club-cup-part" data-part="group" :aria-label="group.stage">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-2.5">
                <p v-if="hasBoth" class="w-full text-[11px] font-semibold uppercase tracking-wider text-miq-muted">{{ group.stage }}</p>
                <p v-if="group.place" class="flex items-baseline gap-1.5">
                    <span class="num-display text-3xl leading-none text-miq-ink">{{ group.place }}</span>
                    <span class="text-sm text-miq-muted">место из {{ group.teams }} · {{ group.name }}</span>
                </p>
                <span v-if="!hasBoth" class="miq-club-head-pos" :data-zone="group.zone || undefined">{{ cup.status_label }}</span>
                <span v-else-if="group.zone_label" class="miq-club-head-pos" :data-zone="group.zone || undefined">{{ group.zone_label }}</span>
                <p v-if="group.zone_label && !hasBoth" class="w-full text-xs" :class="STATUS_CLASSES[cup.status]">{{ group.zone_label }}</p>
                <p v-if="!group.place" class="w-full text-xs text-miq-muted">Таблицы и сетки турнира появятся после первых матчей.</p>
            </div>

            <div v-if="group.table?.length" class="overflow-x-auto">
                <table class="miq-club-table">
                    <thead>
                        <tr>
                            <th class="is-left" title="Место в группе">#</th>
                            <th class="is-left">{{ group.name }}</th>
                            <th title="Игры">И</th>
                            <th title="Победы в основное время">В</th>
                            <th v-if="hasPens" title="Победы по пенальти">ВП</th>
                            <th v-if="showDraws" title="Ничьи">Н</th>
                            <th v-if="hasPens" title="Поражения по пенальти">ПП</th>
                            <th title="Поражения в основное время">П</th>
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
                            <td v-if="hasPens">{{ row.pen_wins }}</td>
                            <td v-if="showDraws">{{ row.draws }}</td>
                            <td v-if="hasPens">{{ row.pen_losses }}</td>
                            <td>{{ row.losses }}</td>
                            <td>{{ signed(row.gd) }}</td>
                            <td class="is-strong">{{ row.pts }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="knockout"
            class="miq-club-cup-part"
            :class="{ 'border-t': hasBoth }"
            data-part="knockout"
            :aria-label="knockout.stage"
            :style="hasBoth ? 'border-color: color-mix(in srgb, var(--miq-line) 60%, transparent)' : undefined"
        >
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-2.5">
                <p v-if="hasBoth" class="w-full text-[11px] font-semibold uppercase tracking-wider text-miq-muted">{{ knockout.stage }}</p>
                <span class="miq-club-head-pos">{{ knockout.status_label }}</span>
                <p v-if="tie" class="w-full text-xs" :class="STATUS_CLASSES[knockout.status]">
                    {{ tie.round }} · против
                    <Link v-if="tie.opponent_id" :href="route('clubs.show', tie.opponent_id)" class="miq-club-link">{{ tie.opponent }}</Link>
                    <template v-else>{{ tie.opponent }}</template>
                    <template v-if="tieScore"> · {{ tieScore }}</template>
                </p>
            </div>
        </section>

        <div v-if="!group && !knockout" class="px-3.5 py-2.5">
            <span class="miq-club-head-pos">{{ cup.status_label }}</span>
            <p class="mt-1 text-xs text-miq-muted">Таблицы и сетки турнира появятся после первых матчей.</p>
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
