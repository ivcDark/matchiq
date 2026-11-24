<script setup>
defineProps({
    modelValue: { type: String, required: true },
    label: { type: String, default: 'Просмотр' },
});

defineEmits(['update:modelValue']);

const OPTIONS = [
    { id: 'overall', label: 'В целом' },
    { id: 'home', label: 'Хозяева' },
    { id: 'away', label: 'Гости' },
];
</script>

<template>
    <div class="miq-tt-tabs" role="group" :aria-label="label">
        <span class="miq-tt-tabs-label">{{ label }}:</span>
        <button
            v-for="option in OPTIONS"
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
