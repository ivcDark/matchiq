<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Общий шаблон профиля игрока: /users/{ник}, позже — /me.
 * Данные приходят одним контрактом (profile, ratings, championships, bestPick, frequentEvents).
 */
const props = defineProps({
    profile: { type: Object, required: true },
    ratings: { type: Array, default: () => [] },
    championships: { type: Array, default: () => [] },
    bestPick: { type: Object, default: null },
    frequentEvents: { type: Array, default: () => [] },
});

const activeCategory = ref(props.frequentEvents[0]?.id ?? null);

const currentCategory = computed(
    () => props.frequentEvents.find((c) => c.id === activeCategory.value) ?? null,
);

const maxEventCount = computed(() =>
    Math.max(1, ...(currentCategory.value?.items ?? []).map((i) => i.count)),
);

const maxChampionshipPicks = computed(() =>
    Math.max(1, ...props.championships.map((c) => c.picks)),
);

function formatNumber(n) {
    return new Intl.NumberFormat('ru-RU').format(n);
}

function hitTone(accuracy) {
    if (accuracy >= 55) return 'is-good';
    if (accuracy < 45) return 'is-bad';
    return 'is-mid';
}
</script>

<template>
    <div class="miq-up">
        <Link :href="route('leaderboard')" class="miq-up-back">
            ← К рейтингу
        </Link>

        <section class="miq-panel miq-up-hero">
            <PlayerAvatar :name="profile.nick" size="lg" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="miq-display truncate text-2xl font-bold text-miq-ink sm:text-3xl">
                        {{ profile.nick }}
                    </h1>
                    <span v-if="profile.is_me" class="miq-lb-me-tag">Это вы</span>
                </div>
                <p class="mt-1 text-sm text-miq-muted">
                    На MatchIQ с {{ profile.registered_label }}
                </p>
            </div>
            <dl class="miq-up-hero-stats">
                <div>
                    <dt>Место</dt>
                    <dd class="num-display">
                        {{ profile.rank }}
                        <span class="miq-up-of">из {{ formatNumber(profile.total_players) }}</span>
                    </dd>
                </div>
                <div>
                    <dt>Очки</dt>
                    <dd class="num-display is-points">{{ formatNumber(profile.points) }}</dd>
                </div>
            </dl>
        </section>

        <section class="miq-panel" aria-labelledby="up-rating">
            <div class="miq-panel-head">
                <h2 id="up-rating" class="miq-panel-title">Рейтинг</h2>
            </div>
            <div class="miq-up-tiles">
                <div v-for="r in ratings" :key="r.id" class="miq-up-tile">
                    <div class="miq-up-tile-label">{{ r.label }}</div>
                    <div class="num-display miq-up-tile-rank">
                        <span class="miq-up-hash">№</span>{{ r.rank }}
                    </div>
                    <div class="num-display miq-up-tile-points">
                        {{ formatNumber(r.points) }} <span>очков</span>
                    </div>
                    <div class="miq-up-tile-meta">
                        {{ r.correct }} из {{ r.picks }} · {{ r.accuracy }}%
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="miq-panel" aria-labelledby="up-leagues">
                <div class="miq-panel-head">
                    <h2 id="up-leagues" class="miq-panel-title">Любимые чемпионаты</h2>
                </div>
                <ul v-if="championships.length" class="miq-up-bars">
                    <li v-for="(c, index) in championships" :key="c.id">
                        <div class="miq-up-bar-row">
                            <span class="truncate font-semibold text-miq-ink">{{ c.name }}</span>
                            <span class="num-display miq-up-bar-value">
                                {{ c.share }}% <span>· {{ c.picks }}</span>
                            </span>
                        </div>
                        <div class="miq-up-bar-track">
                            <div
                                class="miq-up-bar-fill"
                                :class="{ 'is-lead': index === 0 }"
                                :style="{ width: `${Math.max(3, (c.picks / maxChampionshipPicks) * 100)}%` }"
                            />
                        </div>
                    </li>
                </ul>
                <p v-else class="miq-scorers-empty">Пока нет прогнозов</p>
            </section>

            <section class="miq-panel" aria-labelledby="up-best">
                <div class="miq-panel-head">
                    <h2 id="up-best" class="miq-panel-title">Самый удачный исход</h2>
                </div>
                <div v-if="bestPick" class="miq-up-best">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="miq-league-badge">{{ bestPick.league }}</span>
                        <span class="text-xs text-miq-muted">{{ bestPick.date_label }}</span>
                    </div>
                    <div class="mt-2 text-base font-semibold text-miq-ink">{{ bestPick.match }}</div>
                    <div class="mt-0.5 text-sm text-miq-ink-soft">
                        {{ bestPick.market }}: <strong>{{ bestPick.event }}</strong>
                    </div>
                    <dl class="miq-up-best-stats">
                        <div>
                            <dt>Вероятность</dt>
                            <dd class="num-display">{{ bestPick.probability }}%</dd>
                        </div>
                        <div>
                            <dt>Коэффициент</dt>
                            <dd class="num-display">{{ bestPick.odds.toFixed(2) }}</dd>
                        </div>
                        <div>
                            <dt>Очки</dt>
                            <dd class="num-display is-points">+{{ bestPick.points }}</dd>
                        </div>
                    </dl>
                </div>
                <p v-else class="miq-scorers-empty">Пока нет угаданных прогнозов</p>
            </section>
        </div>

        <section class="miq-panel" aria-labelledby="up-events">
            <div class="miq-panel-head">
                <h2 id="up-events" class="miq-panel-title">Самые частые события</h2>
            </div>
            <div class="px-3.5 pb-3.5 pt-3">
                <div class="miq-up-cat-tabs" role="tablist" aria-label="Категория событий">
                    <button
                        v-for="c in frequentEvents"
                        :key="c.id"
                        type="button"
                        role="tab"
                        class="miq-widget-tab"
                        :class="{ 'is-active': activeCategory === c.id }"
                        :aria-selected="activeCategory === c.id"
                        @click="activeCategory = c.id"
                    >
                        {{ c.label }}
                    </button>
                </div>

                <template v-if="currentCategory && currentCategory.items.length">
                    <div class="miq-up-events-head">
                        <span>Событие</span>
                        <span>Прогнозов</span>
                        <span>Угадано</span>
                    </div>
                    <ul class="miq-up-events">
                        <li v-for="item in currentCategory.items" :key="item.name">
                            <div class="miq-up-event-name">
                                <span class="truncate font-medium text-miq-ink">{{ item.name }}</span>
                                <div class="miq-up-bar-track is-thin">
                                    <div
                                        class="miq-up-bar-fill"
                                        :style="{ width: `${Math.max(4, (item.count / maxEventCount) * 100)}%` }"
                                    />
                                </div>
                            </div>
                            <span class="num-display miq-up-event-count">{{ item.count }}</span>
                            <span class="num-display miq-up-event-hit" :class="hitTone(item.accuracy)">
                                {{ item.accuracy }}%
                            </span>
                        </li>
                    </ul>
                </template>
                <p v-else class="miq-scorers-empty">Пока нет прогнозов в этой категории</p>
            </div>
        </section>
    </div>
</template>
