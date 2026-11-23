<script setup>
import { ref } from 'vue';

const model = defineModel({ type: String, required: true });

defineProps({
    id: { type: String, required: true },
    autocomplete: { type: String, default: 'current-password' },
    required: { type: Boolean, default: false },
});

const visible = ref(false);
</script>

<template>
    <div class="miq-password-wrap">
        <input
            :id="id"
            v-model="model"
            class="miq-input"
            :type="visible ? 'text' : 'password'"
            :autocomplete="autocomplete"
            :required="required"
        >
        <button
            type="button"
            class="miq-password-toggle"
            :aria-pressed="visible"
            :aria-label="visible ? 'Скрыть пароль' : 'Показать пароль'"
            @click="visible = !visible"
        >
            {{ visible ? 'Скрыть' : 'Показать' }}
        </button>
    </div>
</template>
