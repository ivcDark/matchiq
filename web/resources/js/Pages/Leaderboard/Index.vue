<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import RatingRules from '@/Components/RatingRules.vue';
import SeasonNote from '@/Components/SeasonNote.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    period: { type: String, default: 'season' },
    periods: { type: Array, default: () => [] },
    season: { type: Object, default: null },
    entries: { type: Array, default: () => [] },
    totalPlayers: { type: Number, default: 0 },
    currentUserRank: { type: Number, default: null },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);

const HOWTO_HASH = '#how-it-works';
const rulesOpen = ref(false);

onMounted(() => {
    if (window.location.hash === HOWTO_HASH) {
        rulesOpen.value = true;
        nextTick(() => document.getElementById('how-it-works')?.scrollIntoView({ block: 'start' }));
    }
});

const podium = computed(() => props.entries.slice(0, 3));
const podiumOrder = ['is-first', 'is-second', 'is-third'];
const MEDALS = { 1: 'gold', 2: 'silver', 3: 'bronze' };

function medalOf(rank) {
    return MEDALS[rank];
}

const periodLabel = computed(
    () => props.periods.find((p) => p.id === props.period)?.label ?? '',
);

function selectPeriod(id) {
    if (id === props.period) return;
    router.get(
        route('leaderboard'),
        { period: id },
        { preserveScroll: true, preserveState: true, only: ['period', 'entries', 'currentUserRank'] },
    );
}

function openProfile(event, entry) {
    // Ссылка в нике сама обрабатывает свой клик (в т.ч. Enter с клавиатуры).
    if (event.target.closest('a')) return;

    const url = route('users.show', entry.id);
    if (event.ctrlKey || event.metaKey) {
        window.open(url, '_blank', 'noopener');
        return;
    }
    router.visit(url);
}

function pluralize(n, forms) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return forms[0];
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return forms[1];
    return forms[2];
}

function formatNumber(n) {
    return new Intl.NumberFormat('ru-RU').format(n);
}
</script>

<template>
    <Head title="Рейтинг" />

    <AppLayout>
        <div class="miq-lb">
            <header class="miq-lb-head">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 class="miq-display text-3xl font-bold text-miq-ink sm:text-4xl">
                            Рейтинг
                        </h1>
                        <button
                            type="button"
                            class="miq-rules-toggle"
                            :class="{ 'is-open': rulesOpen }"
                            :aria-expanded="rulesOpen"
                            aria-controls="how-it-works"
                            @click="rulesOpen = !rulesOpen"
                        >
                            Как работает рейтинг
                            <svg
                                class="miq-rules-chevron"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M5 7.5l5 5 5-5" />
                            </svg>
                        </button>
                    </div>
                    <p class="mt-1 text-sm text-miq-muted">
                        Лучшие по очкам за прогнозы · {{ periodLabel }} ·
                        {{ formatNumber(totalPlayers) }}
                        {{ pluralize(totalPlayers, ['игрок', 'игрока', 'игроков']) }}
                    </p>
                </div>

                <div
                    class="miq-lb-tabs"
                    role="tablist"
                    aria-label="Период рейтинга"
                >
                    <button
                        v-for="tab in periods"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        class="miq-widget-tab"
                        :class="{ 'is-active': period === tab.id }"
                        :aria-selected="period === tab.id"
                        :title="tab.hint"
                        @click="selectPeriod(tab.id)"
                    >
                        {{ tab.label }}
                        <span v-if="tab.caption" class="miq-lb-tab-caption">{{ tab.caption }}</span>
                    </button>
                </div>
            </header>

            <section
                v-show="rulesOpen"
                id="how-it-works"
                class="miq-panel miq-rules-panel"
                aria-label="Как работает рейтинг"
            >
                <RatingRules :season="season" />
            </section>

            <SeasonNote v-if="period === 'season' && season" :season="season" />

            <p class="miq-lb-rules">
                Выберите одно событие на матч до старта. Если оно сыграло — чем ниже
                вероятность модели, тем больше очков. Промах — 0.
            </p>

            <div
                v-if="entries.length === 0"
                class="miq-panel p-8 text-center text-sm text-miq-muted"
            >
                Пока никто не набрал очков —
                <Link :href="route('matches.index')" class="font-semibold text-miq-pitch hover:underline">
                    сделайте первый выбор на матче
                </Link>.
            </div>

            <template v-else>
                <section class="miq-lb-podium" aria-label="Топ-3">
                    <Link
                        v-for="(entry, index) in podium"
                        :key="entry.rank"
                        :href="route('users.show', entry.id)"
                        class="miq-lb-podium-card"
                        :data-medal="medalOf(entry.rank)"
                        :class="[podiumOrder[index], { 'is-me': entry.is_me }]"
                    >
                        <span class="miq-lb-medal num-display" :data-medal="medalOf(entry.rank)">{{ entry.rank }}</span>
                        <PlayerAvatar :name="entry.name" :size="index === 0 ? 'lg' : 'md'" />
                        <div class="miq-lb-podium-name" :title="entry.name">
                            {{ entry.name }}
                            <span v-if="entry.is_me" class="miq-lb-me-tag">Вы</span>
                        </div>
                        <div class="num-display miq-lb-podium-points">
                            {{ formatNumber(entry.points) }}
                        </div>
                        <div class="miq-lb-podium-caption">очков</div>
                    </Link>
                </section>

                <section class="miq-panel">
                    <div class="miq-panel-head">
                        <h2 class="miq-panel-title">Таблица лидеров</h2>
                        <span v-if="currentUserRank" class="text-xs font-semibold text-miq-pitch-deep">
                            Ваше место: {{ currentUserRank }}
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="miq-lb-table">
                            <thead>
                                <tr>
                                    <th class="is-rank">Место</th>
                                    <th class="is-player">Игрок</th>
                                    <th class="hidden sm:table-cell">Прогнозов</th>
                                    <th class="hidden sm:table-cell">Угадано</th>
                                    <th>Точность</th>
                                    <th>Очки</th>
                                    <th class="hidden md:table-cell">Серия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in entries"
                                    :key="entry.rank"
                                    class="miq-lb-row"
                                    :data-medal="medalOf(entry.rank)"
                                    :class="{ 'is-me': entry.is_me }"
                                    :aria-current="entry.is_me ? 'true' : undefined"
                                    @click="openProfile($event, entry)"
                                >
                                    <td class="is-rank">
                                        <span class="miq-rank" :data-medal="medalOf(entry.rank)">
                                            {{ entry.rank }}
                                        </span>
                                    </td>
                                    <td class="is-player">
                                        <Link
                                            :href="route('users.show', entry.id)"
                                            class="miq-lb-player-link"
                                        >
                                            <PlayerAvatar :name="entry.name" size="sm" />
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="truncate font-semibold text-miq-ink">
                                                        {{ entry.name }}
                                                    </span>
                                                    <span v-if="entry.is_me" class="miq-lb-me-tag">Вы</span>
                                                </div>
                                                <div class="miq-lb-subline sm:hidden">
                                                    {{ entry.correct }} из {{ entry.picks }} · серия {{ entry.streak }}
                                                </div>
                                            </div>
                                        </Link>
                                    </td>
                                    <td class="num-display hidden sm:table-cell">{{ entry.picks }}</td>
                                    <td class="num-display hidden sm:table-cell">{{ entry.correct }}</td>
                                    <td class="num-display">{{ entry.accuracy }}%</td>
                                    <td class="num-display is-points">{{ formatNumber(entry.points) }}</td>
                                    <td class="hidden md:table-cell">
                                        <span class="miq-lb-streak num-display" :class="{ 'is-hot': entry.streak >= 5 }">
                                            {{ entry.streak }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <p v-if="isGuest" class="miq-lb-cta">
                <Link :href="route('register')" class="font-semibold text-miq-pitch hover:underline">
                    Зарегистрируйтесь, чтобы участвовать
                </Link>
                в рейтинге или
                <Link :href="route('login')" class="font-semibold text-miq-pitch hover:underline">войдите</Link>.
            </p>
        </div>
    </AppLayout>
</template>
