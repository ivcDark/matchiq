<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    /** URL фото; пустой → fallback */
    photoUrl: { type: String, default: null },
    /** Полное имя «Фамилия И.» или любое — для инициалов */
    name: { type: String, default: '' },
    alt: { type: String, default: '' },
    size: { type: String, default: 'md' }, // sm | md | lg
});

const imgFailed = ref(false);

watch(
    () => props.photoUrl,
    () => {
        imgFailed.value = false;
    },
);

const showPhoto = computed(
    () => Boolean(props.photoUrl) && !imgFailed.value,
);

/** Инициалы: «Глушенков М.» → ГМ; «М. Глушенков» → МГ */
const initials = computed(() => {
    const raw = (props.name || '').trim();
    if (!raw) return '?';
    const parts = raw.split(/\s+/).filter(Boolean);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    const a = parts[0].replace(/[^0-9A-Za-zА-Яа-яЁё]/gu, '').slice(0, 1);
    const b = parts[1].replace(/[^0-9A-Za-zА-Яа-яЁё]/gu, '').slice(0, 1);
    return `${a}${b}`.toUpperCase() || '?';
});
</script>

<template>
    <div
        class="miq-player-avatar"
        :class="[`is-${size}`, { 'has-photo': showPhoto }]"
        :aria-label="alt || name || 'Игрок'"
        role="img"
    >
        <img
            v-if="showPhoto"
            class="miq-player-avatar-img"
            :src="photoUrl"
            :alt="alt || name || ''"
            loading="lazy"
            @error="imgFailed = true"
        >
        <span
            v-else
            class="miq-player-avatar-fallback"
            aria-hidden="true"
        >
            <span class="miq-player-avatar-initials">{{ initials }}</span>
        </span>
    </div>
</template>
