<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const sections = [
    {
        title: 'Что такое MatchIQ',
        paragraphs: [
            'MatchIQ — сервис о футбольных матчах. Мы показываем, как вероятнее всего может сложиться игра, и предлагаем проверить своё чутьё в рейтинге.',
            'Сервис только про футбол: другие виды спорта мы не ведём.',
        ],
    },
    {
        title: 'Вероятности и события',
        paragraphs: [
            'На странице матча собраны события — например, исход игры или тотал голов — и оценка вероятности каждого от 0 до 100. Чем выше число, тем вероятнее событие по оценке MatchIQ.',
            'Это ориентир, а не гарантия: футбол остаётся непредсказуемым, и даже самое вероятное событие не всегда происходит.',
        ],
    },
    {
        title: 'Рейтинг',
        paragraphs: [
            'Зарегистрированный пользователь может до начала матча выбрать одно событие. Если оно сбылось, начисляются очки: чем менее вероятным было событие, тем больше очков. Если нет — ноль.',
            'Результаты собираются в общую таблицу по неделям, месяцам, сезонам и за всё время.',
        ],
        link: { label: 'Открыть рейтинг', href: 'leaderboard' },
    },
    {
        title: 'Откуда данные',
        paragraphs: [
            'Расписание, результаты и статистика матчей приходят от поставщика спортивных данных SStats. Мы регулярно обновляем их и показываем в удобном виде.',
            'Пока в работе одна лига — Российская Премьер-лига. Остальные чемпионаты подключаем постепенно.',
        ],
    },
    {
        title: 'Контакты',
        paragraphs: [
            'Вопросы, замечания и предложения по сервису можно будет отправить по почте. Адрес появится здесь позже.',
        ],
    },
];
</script>

<template>
    <Head title="О проекте" />

    <AppLayout>
        <article class="mx-auto max-w-3xl">
            <header class="mb-6">
                <h1 class="miq-display text-3xl font-bold text-miq-ink sm:text-4xl">О проекте</h1>
                <p class="mt-1 text-sm text-miq-muted">
                    Коротко о том, что такое MatchIQ и как им пользоваться
                </p>
            </header>

            <div class="space-y-4">
                <section
                    v-for="section in sections"
                    :key="section.title"
                    class="rounded-xl border border-miq-line/70 bg-white/60 p-5 sm:p-6"
                >
                    <h2 class="miq-display text-xl font-bold text-miq-ink">{{ section.title }}</h2>
                    <p
                        v-for="(text, i) in section.paragraphs"
                        :key="i"
                        class="mt-2 text-[15px] leading-relaxed text-miq-ink-soft"
                    >
                        {{ text }}
                    </p>
                    <Link
                        v-if="section.link"
                        :href="route(section.link.href)"
                        class="mt-3 inline-block text-sm font-semibold text-miq-pitch transition hover:text-miq-pitch-deep"
                    >
                        {{ section.link.label }} →
                    </Link>
                </section>
            </div>

            <p class="mt-6 text-sm text-miq-muted">
                Готовы начать?
                <Link
                    :href="route('matches.index')"
                    class="font-semibold text-miq-pitch transition hover:text-miq-pitch-deep"
                >
                    Перейти к матчам
                </Link>
            </p>
        </article>
    </AppLayout>
</template>
