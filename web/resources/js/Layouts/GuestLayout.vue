<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="miq-atmosphere min-h-screen">
        <div class="miq-shell flex min-h-screen flex-col">
            <header class="border-b border-miq-line/60 bg-white/35 backdrop-blur-[2px]">
                <div class="mx-auto flex max-w-home items-end justify-between px-4 pb-4 pt-5 sm:px-6 sm:pt-6">
                    <Link
                        :href="route('matches.index')"
                        class="miq-brand block text-[2.25rem] leading-none text-miq-ink sm:text-[2.5rem]"
                    >
                        MatchIQ
                    </Link>
                    <Link
                        :href="route('matches.index')"
                        class="pb-1 text-sm font-medium text-miq-ink-soft transition hover:text-miq-pitch"
                    >
                        К матчам
                    </Link>
                </div>
            </header>

            <main class="mx-auto flex w-full max-w-home flex-1 items-start justify-center px-4 py-8 sm:px-6 sm:py-12">
                <div class="miq-panel w-full max-w-md animate-miq-fade-up">
                    <div class="miq-panel-head">
                        <h1 v-if="title" class="miq-panel-title">{{ title }}</h1>
                        <p v-if="subtitle" class="text-xs text-miq-muted">{{ subtitle }}</p>
                    </div>
                    <div class="px-3.5 pb-4 pt-1 sm:px-4">
                        <slot />
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
