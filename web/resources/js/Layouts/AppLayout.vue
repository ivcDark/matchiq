<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="miq-atmosphere min-h-screen">
        <div class="miq-shell flex min-h-screen flex-col">
            <header class="border-b border-miq-line/60 bg-white/35 backdrop-blur-[2px]">
                <div
                    class="mx-auto flex max-w-home flex-col gap-3 px-4 pb-4 pt-5 sm:flex-row sm:items-end sm:justify-between sm:gap-4 sm:px-6 sm:pt-6"
                >
                    <div class="min-w-0">
                        <Link
                            :href="route('matches.index')"
                            class="miq-brand animate-miq-fade-up block text-[2.5rem] leading-none text-miq-ink sm:text-[2.75rem]"
                        >
                            MatchIQ
                        </Link>
                    </div>

                    <nav
                        class="animate-miq-fade flex flex-wrap items-center gap-x-3 gap-y-2 text-sm font-medium text-miq-ink-soft sm:justify-end sm:pb-1"
                        style="animation-delay: 120ms"
                    >
                        <Link
                            :href="route('leaderboard')"
                            class="transition hover:text-miq-pitch"
                        >
                            Рейтинг
                        </Link>
                        <template v-if="user">
                            <Link
                                :href="route('me')"
                                class="transition hover:text-miq-pitch"
                            >
                                Мои выборы
                            </Link>
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="transition hover:text-miq-pitch"
                            >
                                Выйти
                            </Link>
                        </template>
                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="transition hover:text-miq-pitch"
                            >
                                Войти
                            </Link>
                            <Link
                                :href="route('register')"
                                class="rounded-md bg-miq-pitch px-2.5 py-1 text-white transition hover:bg-miq-pitch-deep"
                            >
                                Регистрация
                            </Link>
                        </template>
                    </nav>
                </div>
            </header>

            <main class="mx-auto w-full max-w-home flex-1 px-4 pb-12 pt-6 sm:px-6 sm:pt-8">
                <slot />
            </main>

            <footer class="border-t border-miq-line/50">
                <div
                    class="mx-auto max-w-home px-4 py-5 text-sm text-miq-muted sm:px-6"
                >
                    Откройте матч → события 0–100 → выберите одно в рейтинг.
                </div>
            </footer>
        </div>
    </div>
</template>
