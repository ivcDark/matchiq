<script setup>
import InputError from '@/Components/InputError.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout
        title="Восстановить пароль"
        subtitle="пришлём ссылку на email (пока в лог сервера)"
    >
        <Head title="Восстановить пароль" />

        <p class="mb-3.5 text-sm leading-snug text-miq-ink-soft">
            Укажите email аккаунта — отправим ссылку для нового пароля.
        </p>

        <div
            v-if="status"
            class="miq-auth-status"
        >
            {{ status }}
        </div>

        <form class="space-y-3.5" @submit.prevent="submit">
            <div>
                <label class="miq-label" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="miq-input"
                    required
                    autofocus
                    autocomplete="username"
                >
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <button
                type="submit"
                class="miq-btn-primary w-full"
                :disabled="form.processing"
                :class="{ 'opacity-60': form.processing }"
            >
                Отправить ссылку
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-miq-muted">
            <Link
                :href="route('login')"
                class="font-semibold text-miq-pitch hover:underline"
            >
                Назад ко входу
            </Link>
        </p>
    </GuestLayout>
</template>
