<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, default: null },
    name: { type: String, default: '' },
    size: { type: Number, default: 22 },
});

const failed = ref(false);
watch(() => props.src, () => { failed.value = false; });

const initials = computed(() => {
    const words = String(props.name || '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
});

const box = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }));
</script>

<template>
    <img
        v-if="src && !failed"
        class="miq-team-logo"
        :style="box"
        :src="src"
        alt=""
        loading="lazy"
        decoding="async"
        aria-hidden="true"
        @error="failed = true"
    />
    <span
        v-else
        class="miq-team-logo miq-team-logo--initials"
        :style="{ ...box, fontSize: `${Math.round(size * 0.42)}px` }"
        aria-hidden="true"
    >{{ initials }}</span>
</template>
