<script setup>
import TournamentTopCard from '@/Components/TournamentTopCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tournament: { type: Object, required: true },
    zones: { type: Array, default: () => [] },
    standings: { type: Array, default: () => [] },
    leaders: { type: Object, default: () => ({}) },
    thresholds: { type: Object, default: () => ({}) },
});

const FORM_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };
const FORM_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };

const zoneLabels = computed(() => Object.fromEntries(props.zones.map((z) => [z.id, z.label])));

const progress = computed(() =>
    Math.round((props.tournament.round / props.tournament.rounds_total) * 100),
);

const nf = new Intl.NumberFormat('ru-RU');
const num = (n) => nf.format(n);
const dec = (n, digits = 2) =>
    new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n);

function signed(n) {
    return n > 0 ? `+${n}` : String(n).replace('-', '−');
}

const cards = computed(() => [
    {
        key: 'scorers',
        title: 'Лучшие бомбардиры',
        hint: 'голы · пенальти',
        rows: props.leaders.scorers,
        format: (r) => ({
            main: num(r.goals),
            unit: 'голов',
            meta: `${r.matches} матч. · ${r.penalties} пен.`,
        }),
    },
    {
        key: 'assists',
        title: 'Лучшие ассистенты',
        hint: 'передачи',
        rows: props.leaders.assists,
        format: (r) => ({
            main: num(r.assists),
            unit: 'передач',
            meta: `${r.matches} матч.`,
        }),
    },
    {
        key: 'fastScorers',
        title: 'Самые скорострельные',
        hint: `от ${num(props.thresholds.minutes_scoring)} мин на поле`,
        rows: props.leaders.fastScorers,
        format: (r) => ({
            main: num(r.minutes_per_goal),
            unit: 'мин на 1 гол',
            meta: `${r.goals} гол. за ${num(r.minutes)} мин · ${dec(r.goals_per_90)} гол/90`,
        }),
    },
    {
        key: 'goalkeepers',
        title: 'Лучшие вратари',
        hint: `сухие матчи · от ${num(props.thresholds.minutes_goalkeeper)} мин`,
        rows: props.leaders.goalkeepers,
        format: (r) => ({
            main: num(r.minutes_per_clean_sheet),
            unit: 'мин на 1 сухой',
            meta: `${r.clean_sheets} сух. за ${num(r.minutes)} мин`,
        }),
    },
    {
        key: 'minutes',
        title: 'Больше всех на поле',
        hint: 'сыграно минут',
        rows: props.leaders.minutes,
        format: (r) => ({
            main: num(r.minutes),
            unit: 'минут',
            meta: `${r.matches} матч.`,
        }),
    },
    {
        key: 'ratings',
        title: 'Лучшие по рейтингу',
        hint: `средняя оценка · от ${props.thresholds.matches_rating} матчей`,
        rows: props.leaders.ratings,
        format: (r) => ({
            main: dec(r.rating),
            unit: 'ср. оценка',
            meta: `${r.matches} матч.`,
        }),
    },
]);
</script>

<template>
    <Head :title="`${tournament.name} — турнир`" />

    <AppLayout>
        <div class="miq-tt space-y-4">
            <Link :href="route('matches.index')" class="miq-up-back">← Матчи</Link>

            <section class="miq-panel miq-tt-hero" aria-label="О турнире">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-miq-muted">
                        {{ tournament.country }} · Сезон {{ tournament.season }}
                    </p>
                    <h1 class="miq-display mt-1 text-3xl font-bold text-miq-ink sm:text-4xl">
                        {{ tournament.name }}
                    </h1>
                    <p class="mt-0.5 text-sm text-miq-muted">{{ tournament.full_name }}</p>
                </div>

                <div class="miq-tt-stage">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-miq-muted">
                        {{ tournament.stage }}
                    </p>
                    <p class="mt-1 flex items-baseline gap-1.5 text-miq-ink">
                        <span class="num-display text-3xl leading-none">{{ tournament.round }}</span>
                        <span class="text-sm text-miq-muted">тур из {{ tournament.rounds_total }}</span>
                    </p>
                    <div
                        class="miq-tt-progress"
                        role="progressbar"
                        :aria-valuenow="tournament.round"
                        aria-valuemin="0"
                        :aria-valuemax="tournament.rounds_total"
                        :aria-label="`Сыграно ${tournament.round} туров из ${tournament.rounds_total}`"
                    >
                        <span :style="{ width: `${progress}%` }" />
                    </div>
                </div>
            </section>

            <section class="miq-panel" aria-label="Турнирная таблица">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Турнирная таблица</h2>
                    <p class="text-xs text-miq-muted">после {{ tournament.round }} тура</p>
                </div>

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
                                <th title="Забито — пропущено">З–П</th>
                                <th title="Разница мячей">+/−</th>
                                <th title="Очки">О</th>
                                <th class="is-form" title="Последние 5 матчей · слева старше, справа свежее">
                                    Форма
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in standings"
                                :key="row.team"
                                :data-zone="row.zone || undefined"
                            >
                                <td
                                    class="is-rank"
                                    :title="row.zone ? zoneLabels[row.zone] : undefined"
                                >
                                    {{ row.pos }}
                                </td>
                                <td class="is-team">
                                    <span class="block truncate">{{ row.team }}</span>
                                </td>
                                <td>{{ row.played }}</td>
                                <td>{{ row.wins }}</td>
                                <td>{{ row.draws }}</td>
                                <td>{{ row.losses }}</td>
                                <td>{{ row.gf }}–{{ row.ga }}</td>
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

                <ul class="miq-tt-legend" aria-label="Легенда зон">
                    <li v-for="zone in zones" :key="zone.id" :data-zone="zone.id">
                        <span class="miq-tt-legend-dot" aria-hidden="true" />
                        {{ zone.label }}
                        <span class="text-miq-muted">· {{ zone.range }}</span>
                    </li>
                </ul>
            </section>

            <div class="miq-tt-grid">
                <TournamentTopCard
                    v-for="card in cards"
                    :key="card.key"
                    :title="card.title"
                    :hint="card.hint"
                    :rows="card.rows"
                    :format="card.format"
                />
            </div>
        </div>
    </AppLayout>
</template>
