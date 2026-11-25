<script setup>
defineProps({
    title: { type: String, default: 'Лучший игрок матча' },
});
</script>

<template>
    <span class="miq-motm-star" role="img" :aria-label="title" :title="title" data-testid="motm-star">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path
                d="M12 2.5l2.94 6.07 6.56.9-4.8 4.6 1.2 6.6L12 17.5l-5.9 3.17 1.2-6.6-4.8-4.6 6.56-.9L12 2.5z"
                fill="#facc15"
                stroke="#a16207"
                stroke-width="1.4"
                stroke-linejoin="round"
            />
        </svg>
    </span>
</template>
