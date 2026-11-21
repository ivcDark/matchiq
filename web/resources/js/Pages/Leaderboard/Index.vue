<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    entries: { type: Array, default: () => [] },
    period: { type: String, default: 'all_time' },
});
</script>

<template>
    <Head title="Рейтинг" />

    <AppLayout>
        <h1 class="mb-2 text-2xl font-semibold">Рейтинг</h1>
        <p class="mb-6 text-sm text-slate-600">
            Чем ниже вероятность выбранного события, тем больше баллов при успехе.
            Период: {{ period }}.
        </p>

        <div
            v-if="entries.length === 0"
            class="rounded border border-dashed border-slate-300 p-6 text-slate-500"
        >
            Таблица лидеров пуста — заглушка до появления picks и начисления очков.
        </div>

        <table v-else class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200">
                    <th class="py-2 pr-4">Место</th>
                    <th class="py-2 pr-4">Ник</th>
                    <th class="py-2">Очки</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries" :key="entry.rank" class="border-b border-slate-100">
                    <td class="py-2 pr-4">{{ entry.rank }}</td>
                    <td class="py-2 pr-4">{{ entry.name }}</td>
                    <td class="py-2">{{ entry.points }}</td>
                </tr>
            </tbody>
        </table>
    </AppLayout>
</template>
