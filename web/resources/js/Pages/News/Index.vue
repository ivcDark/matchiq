<script setup>
import NewsCard from '@/Components/NewsCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    items: { type: Array, default: () => [] },
    pagination: { type: Object, required: true },
});
</script>

<template>
    <Head title="Новости" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="miq-display text-3xl font-bold text-miq-ink sm:text-4xl">Новости</h1>
            <p class="mt-1 text-sm text-miq-muted">
                Футбол, турниры и MatchIQ: коротко и по делу
            </p>
        </header>

        <p v-if="!items.length" class="rounded-xl border border-miq-line/70 bg-white/60 p-6 text-miq-muted">
            Новостей пока нет. Загляните позже или перейдите к
            <Link :href="route('matches.index')" class="font-semibold text-miq-pitch hover:underline">
                матчам
            </Link>.
        </p>

        <ul v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="item in items" :key="item.slug">
                <NewsCard :item="item" />
            </li>
        </ul>

        <nav
            v-if="pagination.last_page > 1"
            class="mt-8 flex items-center justify-center gap-4 text-sm font-semibold"
            aria-label="Страницы новостей"
        >
            <Link
                v-if="pagination.prev_url"
                :href="pagination.prev_url"
                class="rounded-md border border-miq-line px-3 py-1.5 text-miq-ink-soft transition hover:border-miq-pitch hover:text-miq-pitch"
            >
                ← Новее
            </Link>
            <span class="text-miq-muted">
                {{ pagination.current_page }} из {{ pagination.last_page }}
            </span>
            <Link
                v-if="pagination.next_url"
                :href="pagination.next_url"
                class="rounded-md border border-miq-line px-3 py-1.5 text-miq-ink-soft transition hover:border-miq-pitch hover:text-miq-pitch"
            >
                Старее →
            </Link>
        </nav>
    </AppLayout>
</template>
