<script setup>
import NewsCard from '@/Components/NewsCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatNewsDate } from '@/lib/news';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    item: { type: Object, required: true },
    more: { type: Array, default: () => [] },
});

const paragraphs = computed(() =>
    props.item.body
        .split(/\n{2,}/)
        .map((p) => p.trim())
        .filter(Boolean),
);
</script>

<template>
    <Head :title="item.title" />

    <AppLayout>
        <article class="mx-auto max-w-3xl">
            <Link
                :href="route('news.index')"
                class="text-sm font-semibold text-miq-pitch transition hover:text-miq-pitch-deep"
            >
                ← Все новости
            </Link>

            <header class="mt-3">
                <div class="flex items-center gap-3 text-sm">
                    <span
                        class="rounded bg-miq-ink px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white"
                    >
                        {{ item.tag }}
                    </span>
                    <time :datetime="item.published_at" class="font-medium text-miq-muted">
                        {{ formatNewsDate(item.published_at) }}
                    </time>
                </div>
                <h1 class="miq-display mt-3 text-3xl font-bold leading-tight text-miq-ink sm:text-4xl">
                    {{ item.title }}
                </h1>
            </header>

            <img
                v-if="item.image_url"
                :src="item.image_url"
                :alt="item.title"
                class="mt-5 aspect-[16/10] w-full rounded-xl border border-miq-line/70 bg-miq-mist-deep object-cover"
            />

            <div class="mt-6 space-y-4 text-[17px] leading-relaxed text-miq-ink-soft">
                <p v-for="(text, index) in paragraphs" :key="index">{{ text }}</p>
            </div>
        </article>

        <section v-if="more.length" class="mt-12">
            <h2 class="miq-display mb-4 text-xl font-bold text-miq-ink">Читайте также</h2>
            <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="entry in more" :key="entry.slug">
                    <NewsCard :item="entry" />
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
