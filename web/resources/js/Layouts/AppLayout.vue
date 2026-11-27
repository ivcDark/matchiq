<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);

const menuOpen = ref(false);

const navItems = computed(() => [
    {
        key: 'matches',
        label: 'Матчи',
        href: route('matches.index'),
        active: route().current('matches.*'),
    },
    {
        key: 'tournaments',
        label: 'Турниры',
        href: `${route('matches.index')}#tournaments`,
        active: route().current('tournaments.*'),
    },
    {
        key: 'clubs',
        label: 'Клубы',
        href: null,
        active: route().current('clubs.*'),
    },
    {
        key: 'leaderboard',
        label: 'Рейтинг',
        href: route('leaderboard'),
        active: route().current('leaderboard'),
    },
    {
        key: 'news',
        label: 'Новости',
        href: route('news.index'),
        active: route().current('news.*'),
    },
]);

watch(
    () => page.url,
    () => {
        menuOpen.value = false;
    },
);

const navLinkBase =
    'relative inline-flex items-center rounded-md px-2.5 py-1.5 text-sm font-semibold transition';
</script>

<template>
    <div class="miq-atmosphere min-h-screen">
        <div class="miq-shell flex min-h-screen flex-col">
            <header class="border-b border-miq-line/60 bg-white/35 backdrop-blur-[2px]">
                <div class="mx-auto max-w-home px-4 sm:px-6">
                    <div class="flex h-14 items-center gap-3 sm:gap-5">
                        <Link
                            :href="route('matches.index')"
                            class="miq-brand animate-miq-fade-up shrink-0 text-[1.75rem] leading-none text-miq-ink sm:text-[2rem]"
                        >
                            MatchIQ
                        </Link>

                        <nav
                            class="animate-miq-fade hidden items-center gap-0.5 border-l border-miq-line/70 pl-4 md:flex"
                            style="animation-delay: 80ms"
                            aria-label="Основное меню"
                        >
                            <template v-for="item in navItems" :key="item.key">
                                <Link
                                    v-if="item.href"
                                    :href="item.href"
                                    :class="[
                                        navLinkBase,
                                        item.active
                                            ? 'text-miq-pitch-deep after:absolute after:inset-x-2.5 after:-bottom-[9px] after:h-0.5 after:rounded-full after:bg-miq-pitch'
                                            : 'text-miq-ink-soft hover:text-miq-pitch',
                                    ]"
                                    :aria-current="item.active ? 'page' : undefined"
                                >
                                    {{ item.label }}
                                </Link>
                                <span
                                    v-else
                                    :class="[navLinkBase, 'cursor-default text-miq-muted']"
                                    aria-disabled="true"
                                    title="Раздел в разработке"
                                >
                                    {{ item.label }}
                                    <span
                                        class="ml-1.5 rounded bg-miq-mist-deep px-1 py-px text-[10px] font-semibold uppercase tracking-wide text-miq-muted"
                                    >
                                        скоро
                                    </span>
                                </span>
                            </template>
                        </nav>

                        <div
                            class="animate-miq-fade ml-auto hidden items-center gap-x-3 text-sm font-medium text-miq-ink-soft md:flex"
                            style="animation-delay: 120ms"
                        >
                            <template v-if="user">
                                <Link :href="route('me')" class="transition hover:text-miq-pitch">
                                    {{ user.name }}
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
                                <Link :href="route('login')" class="transition hover:text-miq-pitch">
                                    Войти
                                </Link>
                                <Link
                                    :href="route('register')"
                                    class="rounded-md bg-miq-pitch px-2.5 py-1 text-white transition hover:bg-miq-pitch-deep"
                                >
                                    Регистрация
                                </Link>
                            </template>
                        </div>

                        <button
                            type="button"
                            class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded-md text-miq-ink-soft transition hover:bg-miq-mist-deep hover:text-miq-pitch md:hidden"
                            :aria-expanded="menuOpen"
                            aria-controls="miq-mobile-menu"
                            aria-label="Меню"
                            @click="menuOpen = !menuOpen"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                aria-hidden="true"
                            >
                                <path v-if="!menuOpen" d="M4 7h16M4 12h16M4 17h16" />
                                <path v-else d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>

                    <div
                        v-show="menuOpen"
                        id="miq-mobile-menu"
                        class="border-t border-miq-line/50 pb-3 pt-2 md:hidden"
                    >
                        <nav class="flex flex-col" aria-label="Основное меню">
                            <template v-for="item in navItems" :key="item.key">
                                <Link
                                    v-if="item.href"
                                    :href="item.href"
                                    class="rounded-md px-2.5 py-2 text-[15px] font-semibold transition"
                                    :class="
                                        item.active
                                            ? 'bg-miq-mist-deep/70 text-miq-pitch-deep'
                                            : 'text-miq-ink-soft hover:bg-miq-mist-deep/50 hover:text-miq-pitch'
                                    "
                                    :aria-current="item.active ? 'page' : undefined"
                                >
                                    {{ item.label }}
                                </Link>
                                <span
                                    v-else
                                    class="flex items-center rounded-md px-2.5 py-2 text-[15px] font-semibold text-miq-muted"
                                    aria-disabled="true"
                                >
                                    {{ item.label }}
                                    <span
                                        class="ml-2 rounded bg-miq-mist-deep px-1 py-px text-[10px] font-semibold uppercase tracking-wide"
                                    >
                                        скоро
                                    </span>
                                </span>
                            </template>
                        </nav>
                        <div
                            class="mt-2 flex items-center gap-4 border-t border-miq-line/50 px-2.5 pt-3 text-sm font-medium text-miq-ink-soft"
                        >
                            <template v-if="user">
                                <Link :href="route('me')" class="transition hover:text-miq-pitch">
                                    {{ user.name }}
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
                                <Link :href="route('login')" class="transition hover:text-miq-pitch">
                                    Войти
                                </Link>
                                <Link
                                    :href="route('register')"
                                    class="rounded-md bg-miq-pitch px-2.5 py-1 text-white transition hover:bg-miq-pitch-deep"
                                >
                                    Регистрация
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-home flex-1 px-4 pb-12 pt-5 sm:px-6 sm:pt-6">
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
