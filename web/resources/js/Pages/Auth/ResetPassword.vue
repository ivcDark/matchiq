<script setup>
import InputError from '@/Components/InputError.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const clientMismatch = computed(() => {
    if (!form.password_confirmation) return '';
    if (form.password !== form.password_confirmation) {
        return 'Пароли не совпадают.';
    }
    return '';
});

const canSubmit = computed(
    () => !form.processing && !clientMismatch.value && form.password.length > 0,
);

const submit = () => {
    if (clientMismatch.value) return;
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout
        title="Новый пароль"
        subtitle="два поля — чтобы не ошибиться"
    >
        <Head title="Новый пароль" />

        <form class="space-y-3.5" @submit.prevent="submit">
            <div>
                <label class="miq-label" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="miq-input"
                    required
                    autocomplete="username"
                >
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <label class="miq-label" for="password">Новый пароль</label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    required
                />
                <p class="mt-1 text-[11px] leading-snug text-miq-muted">
                    Не меньше 8 символов. Лучше буквы и цифры.
                </p>
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <div>
                <label class="miq-label" for="password_confirmation">Повтор пароля</label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    required
                />
                <InputError
                    class="mt-1.5"
                    :message="form.errors.password_confirmation || clientMismatch"
                />
            </div>

            <button
                type="submit"
                class="miq-btn-primary w-full"
                :disabled="!canSubmit"
                :class="{ 'opacity-60': !canSubmit }"
            >
                Сохранить пароль
            </button>
        </form>
    </GuestLayout>
</template>
