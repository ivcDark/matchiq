<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatPoints, pickStatusText } from '@/lib/rating';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    profile: { type: Object, required: true },
    picks: { type: Array, default: () => [] },
});

function statusClass(status) {
    return {
        'is-won': status === 'won',
        'is-lost': status === 'lost',
    };
}

function formatKickoff(iso) {
    if (!iso) return '';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}
</script>

<template>
    <Head title="Мой профиль" />

    <AppLayout>
        <div class="mx-auto max-w-3xl space-y-5">
            <header>
                <h1 class="miq-display text-3xl font-bold text-miq-ink">{{ profile.name }}</h1>
                <p class="mt-1 text-sm text-miq-muted">{{ profile.email }}</p>
                <p class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="num-display text-3xl text-miq-pitch">{{ profile.points }}</span>
                    <span class="text-sm text-miq-muted">очков</span>
                    <Link :href="route('leaderboard')" class="text-sm font-semibold text-miq-pitch-deep hover:underline">
                        К рейтингу →
                    </Link>
                </p>
            </header>

            <section class="miq-panel">
                <div class="miq-panel-head">
                    <h2 class="miq-panel-title">Мои выборы</h2>
                </div>

                <p v-if="picks.length === 0" class="p-6 text-center text-sm text-miq-muted">
                    Пока нет выбранных событий.
                    <Link :href="route('matches.index')" class="font-semibold text-miq-pitch hover:underline">
                        Выберите матч
                    </Link>
                    и поставьте событие.
                </p>

                <ul v-else class="divide-y divide-miq-line/50">
                    <li
                        v-for="pick in picks"
                        :key="pick.match_id"
                        class="flex items-center justify-between gap-3 px-3.5 py-3"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="route('matches.show', pick.match_id)"
                                class="block truncate text-sm font-semibold text-miq-ink hover:underline"
                            >
                                {{ pick.match }}
                            </Link>
                            <p class="truncate text-xs text-miq-muted">
                                {{ pick.market }} · {{ pick.event }} · {{ pick.probability }}%
                                <span v-if="pick.kickoff_at">· {{ formatKickoff(pick.kickoff_at) }}</span>
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p
                                class="num-display text-lg"
                                :class="pick.status === 'won' ? 'text-miq-pitch-deep' : 'text-miq-muted'"
                            >
                                {{ pick.status === 'won' ? formatPoints(pick.points) : pick.status === 'lost' ? '0' : '—' }}
                            </p>
                            <p class="miq-pick-status" :class="statusClass(pick.status)">
                                {{ pickStatusText({ status: pick.status, points: pick.points }) }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
