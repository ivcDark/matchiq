<script setup>
import ClubBestPlayers from '@/Components/ClubBestPlayers.vue';
import ClubCupStatus from '@/Components/ClubCupStatus.vue';
import ClubGoalsSplit from '@/Components/ClubGoalsSplit.vue';
import ClubPlayersTable from '@/Components/ClubPlayersTable.vue';
import ClubStandingSpot from '@/Components/ClubStandingSpot.vue';
import ClubTournamentSwitch from '@/Components/ClubTournamentSwitch.vue';
import ClubTournamentStats from '@/Components/ClubTournamentStats.vue';
import TournamentContextTabs from '@/Components/TournamentContextTabs.vue';
import TournamentSectionNav from '@/Components/TournamentSectionNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    club: { type: Object, required: true },
    standing: { type: Object, required: true },
    cup: { type: Object, required: true },
    tournaments: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    goals: { type: Object, required: true },
    players: { type: Object, required: true },
});

const SECTIONS = [
    { id: 'summary', label: 'Сводка' },
    { id: 'calendar', label: 'Календарь' },
    { id: 'results', label: 'Результаты' },
    { id: 'stats', label: 'Статистика' },
];
const DEFAULT_SECTION = SECTIONS[0].id;

const PLACEHOLDERS = {
    calendar: 'Календарь матчей клуба появится позже.',
    results: 'Результаты сыгранных матчей клуба появятся позже.',
    stats: 'Расширенная статистика клуба появится позже.',
};

function sectionFromHash() {
    if (typeof window === 'undefined') {
        return DEFAULT_SECTION;
    }
    const id = window.location.hash.replace('#', '');

    return SECTIONS.some((s) => s.id === id) ? id : DEFAULT_SECTION;
}

const section = ref(sectionFromHash());

watch(section, (id) => {
    const hash = id === DEFAULT_SECTION ? '' : `#${id}`;
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}${hash}`);
});

const context = ref('overall');
const tournament = ref(props.tournaments[0]?.key ?? 'rpl');

const CONTEXT_HINTS = { overall: 'все матчи', home: 'домашние матчи', away: 'гостевые матчи' };

const squad = computed(() => props.players[tournament.value]?.[context.value] ?? []);
const goalSplit = computed(() => props.goals[tournament.value][context.value]);
const tablesStats = computed(() => props.stats[context.value]);
</script>

<template>
    <Head :title="`${club.name} — клуб`" />

    <AppLayout>
        <div class="miq-tt space-y-4">
            <Link :href="`${route('tournaments.show', club.tournament_id)}#standings`" class="miq-up-back">← Турнирная таблица</Link>

            <header class="miq-panel" aria-label="О клубе">
                <div class="miq-tt-head-top">
                    <div class="min-w-0">
                        <p class="miq-tt-kicker">Россия · {{ club.city }}</p>
                        <h1 class="miq-display text-xl font-bold leading-tight text-miq-ink sm:text-2xl">{{ club.name }}</h1>
                    </div>
                    <p class="miq-club-head-pos" :title="`${standing.tournament}, после ${club.round} тура`">
                        <span class="num-display text-lg leading-none text-miq-ink">{{ standing.position }}</span>
                        <span class="text-xs text-miq-muted">место · {{ standing.tournament }} · {{ standing.pts }} очк.</span>
                        <span class="hidden text-xs text-miq-muted sm:inline">· сезон {{ club.season }}</span>
                    </p>
                </div>
            </header>

            <TournamentSectionNav v-model="section" :sections="SECTIONS" id-prefix="club" label="Разделы клуба" />

            <div
                v-if="section === 'summary'"
                id="club-panel-summary"
                class="space-y-4"
                role="tabpanel"
                aria-labelledby="club-tab-summary"
            >
                <div class="miq-club-grid">
                    <ClubStandingSpot :standing="standing" :round="club.round" />
                    <ClubCupStatus :cup="cup" :team="club.name" />
                </div>

                <div class="miq-club-filters">
                    <TournamentContextTabs v-model="context" label="Матчи" />
                </div>

                <ClubTournamentStats :stats="tablesStats" :hint="CONTEXT_HINTS[context]" />

                <div class="miq-club-grid-wide">
                    <ClubGoalsSplit :goals="goalSplit" :hint="CONTEXT_HINTS[context]">
                        <template #controls><ClubTournamentSwitch v-model="tournament" :tournaments="tournaments" /></template>
                    </ClubGoalsSplit>
                    <ClubBestPlayers :players="squad" :hint="CONTEXT_HINTS[context]">
                        <template #controls><ClubTournamentSwitch v-model="tournament" :tournaments="tournaments" /></template>
                    </ClubBestPlayers>
                </div>

                <ClubPlayersTable :players="squad" :hint="CONTEXT_HINTS[context]">
                    <template #controls><ClubTournamentSwitch v-model="tournament" :tournaments="tournaments" /></template>
                </ClubPlayersTable>
            </div>

            <div
                v-else
                :id="`club-panel-${section}`"
                class="miq-panel"
                role="tabpanel"
                :aria-labelledby="`club-tab-${section}`"
            >
                <p class="miq-club-placeholder">{{ PLACEHOLDERS[section] }}</p>
            </div>
        </div>
    </AppLayout>
</template>
