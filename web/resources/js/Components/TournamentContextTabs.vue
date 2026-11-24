<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, required: true },
    label: { type: String, default: 'Просмотр' },
    overallLabel: { type: String, default: 'В целом' },
});

defineEmits(['update:modelValue']);

const options = computed(() => [
    { id: 'overall', label: props.overallLabel },
    { id: 'home', label: 'Хозяева' },
    { id: 'away', label: 'Гости' },
]);
</script>

<template>
    <div class="miq-tt-tabs" role="group" :aria-label="label">
        <span class="miq-tt-tabs-label">{{ label }}:</span>
        <button
            v-for="option in options"
            :key="option.id"
            type="button"
            class="miq-tt-tab"
            :class="{ 'is-active': modelValue === option.id }"
            :aria-pressed="modelValue === option.id"
            @click="$emit('update:modelValue', option.id)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
