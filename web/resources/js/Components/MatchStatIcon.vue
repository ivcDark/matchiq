<script setup>
defineProps({
    /** matches | goals | assists | yellow */
    kind: { type: String, required: true },
});
</script>

<template>
    <!-- Матчи: календарь -->
    <svg
        v-if="kind === 'matches'"
        class="miq-stat-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <rect x="3.5" y="5" width="17" height="15" rx="2" />
        <path d="M8 3.5v3M16 3.5v3M3.5 10h17" />
        <circle cx="12" cy="15" r="1.4" fill="currentColor" stroke="none" />
    </svg>

    <!-- Голы: мяч -->
    <svg
        v-else-if="kind === 'goals'"
        class="miq-stat-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <circle cx="12" cy="12" r="8" />
        <path d="M12 4v16" />
        <path d="M4.6 9.2c2.4 1.1 4.9 1.6 7.4 1.6s5-.5 7.4-1.6" />
        <path d="M4.6 14.8c2.4-1.1 4.9-1.6 7.4-1.6s5 .5 7.4 1.6" />
    </svg>

    <!-- ГП: передача (мяч + стрелка) -->
    <svg
        v-else-if="kind === 'assists'"
        class="miq-stat-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <circle cx="6.2" cy="12" r="2.4" />
        <path d="M10 12h8" />
        <path d="M15.2 8.2 19.5 12l-4.3 3.8" />
    </svg>

    <!-- ЖК: как в блоке судьи -->
    <span
        v-else-if="kind === 'yellow'"
        class="miq-card-icon is-yellow miq-stat-card"
        aria-hidden="true"
    />
</template>
