<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    league: { type: String, default: 'rpl' },
    period: { type: String, default: 'upcoming' },
    matches: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Матчи РПЛ" />

    <AppLayout>
        <h1 class="mb-2 text-2xl font-semibold">Матчи РПЛ</h1>
        <p class="mb-6 text-sm text-slate-600">
            Заглушка списка матчей. Период: {{ period }}. Данные появятся после collector и модели.
        </p>

        <div v-if="matches.length === 0" class="rounded border border-dashed border-slate-300 p-6 text-slate-500">
            Пока нет матчей. Сюда придёт список ближайших / сегодня / завершённых.
        </div>

        <ul v-else class="space-y-2">
            <li v-for="match in matches" :key="match.id">
                <Link
                    :href="route('matches.show', match.id)"
                    class="block rounded border border-slate-200 bg-white px-4 py-3 hover:bg-slate-50"
                >
                    {{ match.home }} — {{ match.away }}
                </Link>
            </li>
        </ul>
    </AppLayout>
</template>
