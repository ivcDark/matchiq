<script setup>
import { formatNewsDate } from '@/lib/news';
import { Link } from '@inertiajs/vue3';

defineProps({
    item: { type: Object, required: true },
});
</script>

<template>
    <Link
        :href="route('news.show', item.slug)"
        class="group flex h-full flex-col overflow-hidden rounded-xl border border-miq-line/70 bg-white/70 transition hover:-translate-y-0.5 hover:border-miq-pitch/50 hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-miq-pitch"
    >
        <div class="relative aspect-[16/10] overflow-hidden bg-miq-mist-deep">
            <img
                v-if="item.image_url"
                :src="item.image_url"
                :alt="item.title"
                loading="lazy"
                class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]"
            />
            <span
                class="absolute left-3 top-3 rounded bg-miq-ink/80 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white"
            >
                {{ item.tag }}
            </span>
        </div>
        <div class="flex flex-1 flex-col gap-2 p-4">
            <time :datetime="item.published_at" class="text-xs font-medium text-miq-muted">
                {{ formatNewsDate(item.published_at) }}
            </time>
            <h2
                class="miq-display text-lg font-bold leading-snug text-miq-ink transition group-hover:text-miq-pitch-deep"
            >
                {{ item.title }}
            </h2>
            <p v-if="item.excerpt" class="line-clamp-3 text-sm leading-relaxed text-miq-ink-soft">
                {{ item.excerpt }}
            </p>
        </div>
    </Link>
</template>
