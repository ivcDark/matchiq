<script setup>
const props = defineProps({
    modelValue: { type: String, required: true },
    sections: { type: Array, required: true },
    idPrefix: { type: String, default: 'tt' },
});

const emit = defineEmits(['update:modelValue']);

function onKeydown(event, index) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!step) {
        return;
    }
    event.preventDefault();
    const next = props.sections[(index + step + props.sections.length) % props.sections.length];
    emit('update:modelValue', next.id);
    document.getElementById(`${props.idPrefix}-tab-${next.id}`)?.focus();
}
</script>

<template>
    <nav class="miq-tt-nav" aria-label="Разделы турнира">
        <div class="miq-tt-nav-list" role="tablist">
            <button
                v-for="(section, index) in sections"
                :id="`${idPrefix}-tab-${section.id}`"
                :key="section.id"
                type="button"
                role="tab"
                class="miq-tt-nav-item"
                :class="{ 'is-active': modelValue === section.id }"
                :aria-selected="modelValue === section.id"
                :aria-controls="`${idPrefix}-panel-${section.id}`"
                :tabindex="modelValue === section.id ? 0 : -1"
                @click="emit('update:modelValue', section.id)"
                @keydown="onKeydown($event, index)"
            >
                {{ section.label }}
            </button>
        </div>
    </nav>
</template>
