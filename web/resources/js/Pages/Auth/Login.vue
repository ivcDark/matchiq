<script setup>
import AuthSocialButtons from '@/Components/AuthSocialButtons.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String, default: null },
});

const page = usePage();
const banner = computed(() => props.status || page.props.flash?.status || null);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout title="Войти" subtitle="email и пароль или соцсеть">
        <Head title="Войти" />

        <div
            v-if="banner"
            class="miq-auth-status"
        >
            {{ banner }}
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

            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="miq-label mb-0" for="password">Пароль</label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-[12px] font-medium text-miq-pitch hover:underline"
                    >
                        Забыли пароль?
                    </Link>
                </div>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="current-password"
                    required
                />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <label class="flex items-center gap-2 text-sm text-miq-ink-soft">
                <Checkbox v-model:checked="form.remember" name="remember" />
                <span>Запомнить меня</span>
            </label>

            <button
                type="submit"
                class="miq-btn-primary w-full"
                :disabled="form.processing"
                :class="{ 'opacity-60': form.processing }"
            >
                Войти
            </button>
        </form>

        <AuthSocialButtons class="mt-4" />

        <p class="mt-4 text-center text-sm text-miq-muted">
            Нет аккаунта?
            <Link
                :href="route('register')"
                class="font-semibold text-miq-pitch hover:underline"
            >
                Регистрация
            </Link>
        </p>
    </GuestLayout>
</template>
