<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    period: { type: String, default: 'season' },
    periods: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    totalPlayers: { type: Number, default: 0 },
    currentUserRank: { type: Number, default: null },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);

const podium = computed(() => props.entries.slice(0, 3));
const podiumOrder = ['is-first', 'is-second', 'is-third'];

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
                    <h1 class="miq-display text-3xl font-bold text-miq-ink sm:text-4xl">
                        Рейтинг
                    </h1>
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
                        @click="selectPeriod(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </header>

            <p class="miq-lb-update" role="note">
                <svg
                    class="miq-lb-update-icon"
                    viewBox="0 0 20 20"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="10" cy="10" r="7.5" />
                    <path d="M10 5.5V10l3 2" />
                </svg>
                <span>Рейтинг обновляется раз в неделю по понедельникам в 06:00 МСК</span>
            </p>

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
                    <article
                        v-for="(entry, index) in podium"
                        :key="entry.rank"
                        class="miq-lb-podium-card"
                        :class="[podiumOrder[index], { 'is-me': entry.is_me }]"
                    >
                        <span class="miq-lb-medal num-display">{{ entry.rank }}</span>
                        <PlayerAvatar :name="entry.name" :size="index === 0 ? 'lg' : 'md'" />
                        <div class="miq-lb-podium-name" :title="entry.name">
                            {{ entry.name }}
                            <span v-if="entry.is_me" class="miq-lb-me-tag">Вы</span>
                        </div>
                        <div class="num-display miq-lb-podium-points">
                            {{ formatNumber(entry.points) }}
                        </div>
                        <div class="miq-lb-podium-caption">очков</div>
                    </article>
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
                                    :class="{ 'is-me': entry.is_me }"
                                    :aria-current="entry.is_me ? 'true' : undefined"
                                >
                                    <td class="is-rank">
                                        <span class="miq-rank" :class="{ 'is-top': entry.rank <= 3 }">
                                            {{ entry.rank }}
                                        </span>
                                    </td>
                                    <td class="is-player">
                                        <div class="flex min-w-0 items-center gap-2.5">
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
                                        </div>
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
