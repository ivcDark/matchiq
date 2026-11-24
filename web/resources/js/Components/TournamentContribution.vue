<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import TournamentContextTabs from '@/Components/TournamentContextTabs.vue';
import { computed, ref } from 'vue';

const MEDALS = { 1: 'gold', 2: 'silver', 3: 'bronze' };

const props = defineProps({
    title: { type: String, required: true },
    hint: { type: String, default: '' },
    /** { overall|home|away: [{ rank, name, team, goals, team_goals, share }] } */
    contribution: { type: Object, required: true },
});

const context = ref('overall');

const rows = computed(() => {
    const list = props.contribution[context.value] ?? [];
    const maxTeam = Math.max(1, ...list.map((r) => r.team_goals));

    return list.map((row) => ({
        ...row,
        trackWidth: `${Math.round((row.team_goals / maxTeam) * 100)}%`,
        fillWidth: `${Math.max(8, Math.round((row.goals / row.team_goals) * 100))}%`,
    }));
});
</script>

<template>
    <section class="miq-panel miq-tt-card" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p v-if="hint" class="text-xs text-miq-muted">{{ hint }}</p>
        </div>

        <TournamentContextTabs v-model="context" />

        <div class="overflow-x-auto">
            <table class="miq-tt-ptable">
                <thead>
                    <tr>
                        <th class="is-rank" title="Место">№</th>
                        <th class="is-player">Игрок</th>
                        <th class="is-team-col is-mobile-hidden">Команда</th>
                        <th class="is-bar is-dbar">Голы игрока / голы команды</th>
                        <th title="Доля голов игрока от голов команды">Вклад</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="`${row.rank}-${row.name}`">
                        <td class="is-rank">
                            <span class="miq-tt-rank" :data-medal="MEDALS[row.rank]">{{ row.rank }}</span>
                        </td>
                        <td class="is-player">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <PlayerAvatar :name="row.name" size="sm" />
                                <span class="min-w-0 truncate text-[13px] font-semibold leading-tight text-miq-ink">
                                    {{ row.name }}
                                    <span class="miq-tt-team sm:hidden">{{ row.team }}</span>
                                </span>
                            </div>
                        </td>
                        <td class="is-team-col is-mobile-hidden">
                            <span class="block truncate">{{ row.team }}</span>
                        </td>
                        <td class="is-bar is-dbar">
                            <div class="miq-tt-dbar">
                                <div class="miq-tt-dbar-track" :style="{ width: row.trackWidth }">
                                    <span class="miq-tt-dbar-fill" :style="{ width: row.fillWidth }" />
                                    <span class="miq-tt-dbar-num is-player">{{ row.goals }}</span>
                                    <span class="miq-tt-dbar-num is-team">{{ row.team_goals }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="is-share">{{ row.share }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
