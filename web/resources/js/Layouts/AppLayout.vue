<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3"
            >
                <Link :href="route('matches.index')" class="text-lg font-semibold tracking-tight">
                    MatchIQ
                </Link>
                <nav class="flex items-center gap-4 text-sm">
                    <Link :href="route('matches.index')" class="hover:underline">Матчи</Link>
                    <Link :href="route('leaderboard')" class="hover:underline">Рейтинг</Link>
                    <template v-if="user">
                        <Link :href="route('me')" class="hover:underline">Профиль</Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="hover:underline"
                        >
                            Выйти
                        </Link>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" class="hover:underline">Вход</Link>
                        <Link :href="route('register')" class="hover:underline">Регистрация</Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
