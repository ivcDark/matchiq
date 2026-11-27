<script setup>
import TournamentAttackDefense from '@/Components/TournamentAttackDefense.vue';
import TournamentContribution from '@/Components/TournamentContribution.vue';
import TournamentForm from '@/Components/TournamentForm.vue';
import TournamentGroupStandings from '@/Components/TournamentGroupStandings.vue';
import TournamentHeader from '@/Components/TournamentHeader.vue';
import TournamentResults from '@/Components/TournamentResults.vue';
import TournamentSchedule from '@/Components/TournamentSchedule.vue';
import TournamentSectionNav from '@/Components/TournamentSectionNav.vue';
import TournamentSortTable from '@/Components/TournamentSortTable.vue';
import TournamentStandings from '@/Components/TournamentStandings.vue';
import TournamentTeamStats from '@/Components/TournamentTeamStats.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    tournament: { type: Object, required: true },
    seasonKey: { type: String, default: '' },
    seasons: { type: Array, default: () => [] },
    zones: { type: Array, default: () => [] },
    standingsMode: { type: String, default: 'league' },
    groupStage: { type: Object, default: null },
    standings: { type: Array, default: () => [] },
    standingsByContext: { type: Object, default: () => ({}) },
    teamForm: { type: Array, default: () => [] },
    schedule: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    leaders: { type: Object, default: () => ({}) },
    attackDefense: { type: Object, default: () => ({}) },
    teamStats: { type: Object, default: () => ({}) },
    contribution: { type: Object, default: () => ({}) },
});

const SECTIONS = [
    { id: 'stats', label: 'Статистика' },
    { id: 'standings', label: 'Турнирная таблица' },
    { id: 'form', label: 'Форма команд' },
    { id: 'schedule', label: 'Расписание' },
    { id: 'results', label: 'Результаты' },
];
const DEFAULT_SECTION = SECTIONS[0].id;

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

const nf = new Intl.NumberFormat('ru-RU');
const num = (n) => nf.format(n);
const dec = (n, digits = 2) =>
    new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n);

const cards = computed(() => [
    {
        key: 'scorers',
        title: 'Лучшие бомбардиры',
        hint: 'голы (с пенальти) · пас · Г+П',
        rows: props.leaders.scorers,
        defaultSort: { key: 'ga', dir: 'desc' },
        columns: [
            {
                key: 'goals',
                label: 'Гол',
                title: 'Голы (в скобках — с пенальти)',
                format: (r) => (r.pens > 0 ? `${r.goals} (${r.pens})` : String(r.goals)),
            },
            { key: 'assists', label: 'Пас', title: 'Голевые передачи' },
            { key: 'ga', label: 'Г+П', title: 'Голы + голевые передачи', bar: true },
        ],
    },
    {
        key: 'assists',
        title: 'Лучшие ассистенты',
        hint: 'голевые передачи',
        rows: props.leaders.assists,
        defaultSort: { key: 'assists', dir: 'desc' },
        columns: [
            { key: 'matches', label: 'Матчи', title: 'Сыграно матчей', hideMobile: true },
            { key: 'assists', label: 'Пас', title: 'Голевые передачи', bar: true },
        ],
    },
    {
        key: 'fastScorers',
        title: 'Самые скорострельные',
        hint: 'голов на 90 минут на поле',
        rows: props.leaders.fastScorers,
        defaultSort: { key: 'goals_per_90', dir: 'desc' },
        columns: [
            {
                key: 'minutes',
                label: 'Минут',
                title: 'Сыграно минут',
                format: (r) => num(r.minutes),
            },
            { key: 'goals', label: 'Голов', title: 'Забито голов' },
            {
                key: 'goals_per_90',
                label: 'Гол/90',
                title: 'Голов на 90 минут на поле (на полный матч)',
                bar: true,
                format: (r) => dec(r.goals_per_90),
            },
            {
                key: 'minutes_per_goal',
                label: 'Мин/гол',
                title: 'Минут на 1 гол',
                firstDir: 'asc',
                hideMobile: true,
                format: (r) => num(r.minutes_per_goal),
            },
        ],
    },
    {
        key: 'goalkeepers',
        title: 'Лучшие вратари',
        hint: 'сухие матчи',
        rows: props.leaders.goalkeepers,
        defaultSort: { key: 'clean_sheets_per_90', dir: 'desc' },
        columns: [
            {
                key: 'minutes',
                label: 'Минут',
                title: 'Сыграно минут',
                format: (r) => num(r.minutes),
            },
            { key: 'clean_sheets', label: 'Сухих', title: 'Сухие матчи' },
            {
                key: 'clean_sheets_per_90',
                label: 'Сух/90',
                title: 'Сухих матчей на 90 минут на поле (на полный матч)',
                bar: true,
                tone: 'coral',
                format: (r) => dec(r.clean_sheets_per_90),
            },
            {
                key: 'minutes_per_clean_sheet',
                label: 'Мин/сух',
                title: 'Минут на 1 сухой матч',
                firstDir: 'asc',
                hideMobile: true,
                format: (r) => num(r.minutes_per_clean_sheet),
            },
        ],
    },
    {
        key: 'minutes',
        title: 'Больше всех на поле',
        hint: 'сыграно минут',
        rows: props.leaders.minutes,
        defaultSort: { key: 'minutes', dir: 'desc' },
        columns: [
            { key: 'matches', label: 'Матчи', title: 'Сыграно матчей', hideMobile: true },
            {
                key: 'minutes',
                label: 'Минут',
                title: 'Сыграно минут',
                bar: true,
                format: (r) => num(r.minutes),
            },
        ],
    },
    {
        key: 'ratings',
        title: 'Лучшие по рейтингу',
        hint: 'средняя оценка за матч',
        rows: props.leaders.ratings,
        defaultSort: { key: 'rating', dir: 'desc' },
        columns: [
            { key: 'matches', label: 'Матчи', title: 'Сыграно матчей', hideMobile: true },
            {
                key: 'rating',
                label: 'Оценка',
                title: 'Средняя оценка за матч',
                bar: true,
                tone: 'coral',
                format: (r) => dec(r.rating),
            },
        ],
    },
]);
</script>

<template>
    <Head :title="`${tournament.name} — турнир`" />

    <AppLayout>
        <div class="miq-tt space-y-4">
            <Link :href="route('matches.index')" class="miq-up-back">← Матчи</Link>

            <TournamentHeader :tournament="tournament" :seasons="seasons" :season-key="seasonKey" />

            <TournamentSectionNav v-model="section" :sections="SECTIONS" id-prefix="tt" />

            <div
                v-if="section === 'stats'"
                id="tt-panel-stats"
                class="space-y-4"
                role="tabpanel"
                aria-labelledby="tt-tab-stats"
            >
                <TournamentGroupStandings
                    v-if="standingsMode === 'groups' && groupStage"
                    :round="tournament.round"
                    :stage="groupStage"
                    :tables="{ overall: standings }"
                />
                <TournamentStandings
                    v-else
                    :round="tournament.round"
                    :zones="zones"
                    :tables="{ overall: standings }"
                />

                <TournamentTeamStats
                    title="Лучшие команды"
                    :hint="`${tournament.name} · топ-5 по каждому показателю`"
                    :stats="teamStats"
                />

                <TournamentAttackDefense
                    title="Атака и защита"
                    :hint="`${tournament.name} · голы после ${tournament.round} тура`"
                    :data="attackDefense"
                />

                <TournamentContribution
                    title="Лучшие игроки"
                    :hint="`${tournament.name} · вклад в голы команды`"
                    :contribution="contribution"
                />

                <div class="miq-tt-grid">
                    <TournamentSortTable
                        v-for="card in cards"
                        :key="card.key"
                        :title="card.title"
                        :hint="card.hint"
                        :rows="card.rows"
                        :columns="card.columns"
                        :default-sort="card.defaultSort"
                    />
                </div>
            </div>

            <div
                v-else-if="section === 'standings'"
                id="tt-panel-standings"
                role="tabpanel"
                aria-labelledby="tt-tab-standings"
            >
                <TournamentGroupStandings
                    v-if="standingsMode === 'groups' && groupStage"
                    :round="tournament.round"
                    :stage="groupStage"
                    :tables="standingsByContext"
                    switchable
                />
                <TournamentStandings
                    v-else
                    :round="tournament.round"
                    :zones="zones"
                    :tables="standingsByContext"
                    switchable
                />
            </div>

            <div v-else-if="section === 'form'" id="tt-panel-form" role="tabpanel" aria-labelledby="tt-tab-form">
                <TournamentForm :round="tournament.round" :teams="teamForm" />
            </div>

            <div
                v-else-if="section === 'schedule'"
                id="tt-panel-schedule"
                role="tabpanel"
                aria-labelledby="tt-tab-schedule"
            >
                <TournamentSchedule :rounds="schedule" />
            </div>

            <div
                v-else-if="section === 'results'"
                id="tt-panel-results"
                role="tabpanel"
                aria-labelledby="tt-tab-results"
            >
                <TournamentResults :rounds="results" />
            </div>
        </div>
    </AppLayout>
</template>
