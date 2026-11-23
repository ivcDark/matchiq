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

    <!-- Голы: футбольный мяч (пятиугольник + швы) -->
    <svg
        v-else-if="kind === 'goals'"
        class="miq-stat-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.55"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <circle cx="12" cy="12" r="8.2" />
        <!-- центральный пятиугольник -->
        <path d="M12 8.2 14.6 10.1 13.6 13.2h-3.2L9.4 10.1Z" />
        <!-- швы от вершин пятиугольника к окружности -->
        <path d="M12 8.2 12 3.8" />
        <path d="M14.6 10.1 19.2 8.4" />
        <path d="M13.6 13.2 16.8 17.6" />
        <path d="M10.4 13.2 7.2 17.6" />
        <path d="M9.4 10.1 4.8 8.4" />
    </svg>

    <!-- ГП: бутса + мяч -->
    <svg
        v-else-if="kind === 'assists'"
        class="miq-stat-icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.55"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <!-- мяч слева -->
        <circle cx="6.2" cy="15.4" r="3.1" />
        <path d="M6.2 12.3v6.2" />
        <path d="M3.6 14.2c.9.4 1.7.6 2.6.6s1.7-.2 2.6-.6" />
        <path d="M3.6 16.6c.9-.4 1.7-.6 2.6-.6s1.7.2 2.6.6" />
        <!-- бутса справа -->
        <path d="M10.2 14.8h6.4c1.4 0 2.5.7 3.1 1.7l.7 1.2H10.2Z" />
        <path d="M10.2 14.8V9.6c0-.9.7-1.6 1.6-1.6h2.2c1.3 0 2.4.9 2.6 2.1l.3 1.7" />
        <path d="M12.2 8v2.2M14.1 8v2.2" />
    </svg>

    <!-- ЖК: как в блоке судьи -->
    <span
        v-else-if="kind === 'yellow'"
        class="miq-card-icon is-yellow miq-stat-card"
        aria-hidden="true"
    />
</template>
