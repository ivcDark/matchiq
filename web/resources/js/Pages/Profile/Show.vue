<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    profile: { type: Object, required: true },
    picks: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Мой профиль" />

    <AppLayout>
        <h1 class="mb-2 text-2xl font-semibold">{{ profile.name }}</h1>
        <p class="mb-1 text-sm text-slate-600">{{ profile.email }}</p>
        <p class="mb-6 text-sm text-slate-600">
            Очки: <strong>{{ profile.points }}</strong>
            ·
            <Link :href="route('leaderboard')" class="underline">К рейтингу</Link>
        </p>

        <h2 class="mb-2 text-lg font-medium">Мои выборы</h2>
        <div
            v-if="picks.length === 0"
            class="rounded border border-dashed border-slate-300 p-6 text-slate-500"
        >
            Пока нет выбранных событий. Здесь будут матч, вероятность, статус и баллы.
        </div>

        <ul v-else class="space-y-2 text-sm">
            <li
                v-for="(pick, index) in picks"
                :key="index"
                class="rounded border border-slate-200 bg-white px-4 py-3"
            >
                {{ pick.match }} · {{ pick.event }} · p={{ pick.probability }} ·
                {{ pick.status }} · {{ pick.points }} баллов
            </li>
        </ul>
    </AppLayout>
</template>
