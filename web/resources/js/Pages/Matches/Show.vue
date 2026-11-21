<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    match: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    preview: { type: String, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <Head :title="`${match.home} — ${match.away}`" />

    <AppLayout>
        <p class="mb-1 text-sm text-slate-500">{{ match.league }} · {{ match.status }}</p>
        <h1 class="mb-6 text-2xl font-semibold">
            {{ match.home }} — {{ match.away }}
        </h1>

        <section class="mb-8">
            <h2 class="mb-2 text-lg font-medium">Вероятности событий</h2>
            <p class="mb-3 text-sm text-slate-600">
                Фильтры: лучшие / худшие / популярные — появятся позже.
            </p>
            <div
                v-if="events.length === 0"
                class="rounded border border-dashed border-slate-300 p-6 text-slate-500"
            >
                События и вероятности ещё не посчитаны.
            </div>
        </section>

        <section class="mb-8">
            <h2 class="mb-2 text-lg font-medium">Прогноз матча</h2>
            <p class="text-slate-600">
                {{ preview || 'Краткий AI-текст (2–4 предложения) появится здесь.' }}
            </p>
        </section>

        <section>
            <p v-if="user" class="text-sm text-slate-600">
                Выбор события в рейтинг — заглушка (1 событие на матч до kickoff).
            </p>
            <p v-else class="text-sm text-slate-600">
                <Link :href="route('login')" class="underline">Войдите</Link>,
                чтобы участвовать в рейтинге.
            </p>
        </section>
    </AppLayout>
</template>
