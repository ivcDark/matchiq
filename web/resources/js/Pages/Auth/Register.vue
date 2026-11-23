<script setup>
import AuthSocialButtons from '@/Components/AuthSocialButtons.vue';
import InputError from '@/Components/InputError.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const banner = computed(() => page.props.flash?.status || null);

const form = useForm({
    name: '',
    email: '',
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
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout
        title="Регистрация"
        subtitle="нужна, чтобы выбирать события в рейтинг"
    >
        <Head title="Регистрация" />

        <div
            v-if="banner"
            class="miq-auth-status"
        >
            {{ banner }}
        </div>

        <form class="space-y-3.5" @submit.prevent="submit">
            <div>
                <label class="miq-label" for="name">Имя (ник для рейтинга)</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="miq-input"
                    required
                    autofocus
                    maxlength="40"
                    autocomplete="nickname"
                >
                <InputError class="mt-1.5" :message="form.errors.name" />
            </div>

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
                <label class="miq-label" for="password">Пароль</label>
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
                Создать аккаунт
            </button>
        </form>

        <AuthSocialButtons class="mt-4" />

        <p class="mt-4 text-center text-sm text-miq-muted">
            Уже есть аккаунт?
            <Link
                :href="route('login')"
                class="font-semibold text-miq-pitch hover:underline"
            >
                Войти
            </Link>
        </p>
    </GuestLayout>
</template>
