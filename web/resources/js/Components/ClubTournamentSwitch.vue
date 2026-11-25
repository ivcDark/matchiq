<script setup>
defineProps({
    modelValue: { type: String, required: true },
    /** [{ key, name }] */
    tournaments: { type: Array, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="miq-club-tsw" role="group" aria-label="Турнир">
        <button
            v-for="t in tournaments"
            :key="t.key"
            type="button"
            class="miq-club-tsw-btn"
            :class="{ 'is-active': modelValue === t.key }"
            :aria-pressed="modelValue === t.key"
            @click="$emit('update:modelValue', t.key)"
        >
            {{ t.name }}
        </button>
    </div>
</template>
