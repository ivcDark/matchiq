<?php

/*
 * Расписание регулярных ingest-прогонов (php artisan schedule:work). Всё настраивается через env.
 * Интервалы «every_minutes»: 0 — задача выключена; < 60 — каждые N минут; кратно 60 — каждые N/60 часов.
 */

$int = fn (string $key, int $default): int => ($v = env($key)) === null || $v === '' ? $default : (int) $v;
$str = fn (string $key, string $default): string => ($v = env($key)) === null || $v === '' ? $default : (string) $v;

return [
    'schedule' => [
        'enabled' => filter_var(env('INGEST_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Slug'и соревнований через запятую. Пусто — все с competitions.status = live и внешним id SStats.
        'competitions' => array_values(array_filter(array_map('trim', explode(',', (string) env('INGEST_COMPETITIONS', ''))))),

        // Расписание и результаты: короткое окно вокруг сегодняшней даты (UTC), часто.
        'fixtures' => [
            'every_minutes' => $int('INGEST_FIXTURES_EVERY_MINUTES', 10),
            'days_back' => $int('INGEST_FIXTURES_DAYS_BACK', 2),
            'days_forward' => $int('INGEST_FIXTURES_DAYS_FORWARD', 2),
        ],

        // Весь текущий сезон без окна: подхватывает переносы матчей и новые туры. Раз в сутки.
        'fixtures_full' => [
            'daily_at' => $str('INGEST_FIXTURES_FULL_DAILY_AT', '03:30'),
        ],

        // Составы, события и статистика матчей, по которым деталей ещё нет (свежие первыми).
        'details_missing' => [
            'every_minutes' => $int('INGEST_DETAILS_MISSING_EVERY_MINUTES', 15),
            'limit' => $int('INGEST_DETAILS_MISSING_LIMIT', 10),
        ],

        // Повторная загрузка недавно завершённых матчей (поздние поправки статистики).
        'details_recent' => [
            'every_minutes' => $int('INGEST_DETAILS_RECENT_EVERY_MINUTES', 60),
            'days_back' => $int('INGEST_DETAILS_RECENT_DAYS_BACK', 1),
            'limit' => $int('INGEST_DETAILS_RECENT_LIMIT', 10),
        ],

        // Справочники и структура сезона — не чаще раза в сутки.
        'catalog' => [
            'daily_at' => $str('INGEST_CATALOG_DAILY_AT', '04:00'),
            'country' => $str('INGEST_CATALOG_COUNTRY', 'Russia'),
            'league' => $str('INGEST_CATALOG_LEAGUE', 'Premier League'),
        ],
        'structure' => [
            'daily_at' => $str('INGEST_STRUCTURE_DAILY_AT', '04:10'),
        ],

        'reap_stuck' => [
            'every_minutes' => $int('INGEST_REAP_EVERY_MINUTES', 10),
        ],
    ],
];
