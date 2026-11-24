<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    tournament: { type: Object, required: true },
    seasons: { type: Array, default: () => [] },
    seasonKey: { type: String, default: '' },
});

const switching = ref(false);

const summary = computed(() => props.tournament.summary);
const activeSeason = computed(() => props.seasons.find((s) => s.key === props.seasonKey));
const progress = computed(() => Math.round((props.tournament.round / props.tournament.rounds_total) * 100));

const dec = (n) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

const seasonNote = computed(() =>
    activeSeason.value?.current ? 'Текущий сезон' : `Срез: после ${props.tournament.round} тура`,
);

function changeSeason(event) {
    const key = event.target.value;
    if (key === props.seasonKey) {
        return;
    }
    const current = props.seasons.find((s) => s.current);
    const hash = typeof window === 'undefined' ? '' : window.location.hash;

    router.get(
        `${route('tournaments.show', props.tournament.id)}${hash}`,
        key === current?.key ? {} : { season: key },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                switching.value = true;
            },
            onFinish: () => {
                switching.value = false;
            },
        },
    );
}
</script>

<template>
    <section class="miq-panel miq-tt-head" aria-label="О турнире">
        <div class="miq-tt-head-top">
            <div class="min-w-0">
                <p class="miq-tt-kicker">
                    {{ tournament.country }} · {{ tournament.full_name }} · {{ summary.teams }} команд
                </p>
                <h1 class="miq-display text-xl font-bold leading-tight text-miq-ink sm:text-2xl">
                    {{ tournament.name }}
                    <span class="miq-tt-stage">{{ tournament.stage }}</span>
                </h1>
            </div>

            <label class="miq-tt-season">
                <span class="miq-tt-season-label">Сезон</span>
                <select
                    class="miq-tt-season-select"
                    :value="seasonKey"
                    :disabled="switching"
                    aria-label="Сезон турнира"
                    @change="changeSeason"
                >
                    <option v-for="season in seasons" :key="season.key" :value="season.key">
                        {{ season.label }}
                    </option>
                </select>
                <span class="miq-tt-season-note">{{ seasonNote }}</span>
            </label>
        </div>

        <dl class="miq-tt-stats">
            <div class="miq-tt-stat">
                <dt>Тур</dt>
                <dd>
                    <span class="miq-tt-stat-num">{{ tournament.round }}</span>
                    <span class="miq-tt-stat-sub">из {{ tournament.rounds_total }}</span>
                </dd>
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

            <div class="miq-tt-stat">
                <dt>Голов</dt>
                <dd>
                    <span class="miq-tt-stat-num">{{ summary.goals }}</span>
                    <span class="miq-tt-stat-sub">{{ dec(summary.goals_per_match) }} за матч</span>
                </dd>
            </div>

            <div class="miq-tt-stat">
                <dt>Лидер</dt>
                <dd>
                    <span class="miq-tt-stat-name">{{ summary.leader.team }}</span>
                    <span class="miq-tt-stat-sub">{{ summary.leader.pts }} очк.</span>
                </dd>
            </div>

            <div class="miq-tt-stat">
                <dt>Отрыв от 2-го</dt>
                <dd>
                    <span class="miq-tt-stat-num">{{ summary.gap > 0 ? `+${summary.gap}` : summary.gap }}</span>
                    <span class="miq-tt-stat-sub">очк.</span>
                </dd>
            </div>
        </dl>
    </section>
</template>
