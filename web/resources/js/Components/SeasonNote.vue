<script setup>
defineProps({
    season: { type: Object, required: true },
    /** Показывать пояснение отдельной строкой (на мобильном нет hover) */
    withHint: { type: Boolean, default: true },
});
</script>

<template>
    <div class="miq-season-note" :title="season.hint">
        <p class="miq-season-note-main">
            <svg
                class="miq-season-note-icon"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <rect x="3" y="4.5" width="14" height="12" rx="2" />
                <path d="M3 8.5h14M7 3v3M13 3v3" />
            </svg>
            <span>
                <strong>{{ season.title }}</strong>
                <span class="miq-season-note-range"> · {{ season.range }}</span>
            </span>
        </p>
        <p v-if="withHint" class="miq-season-note-hint">{{ season.hint }}.</p>
    </div>
</template>
