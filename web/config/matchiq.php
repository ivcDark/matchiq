<?php

return [
    /*
    | Локаль отображаемых названий (лиги, команды, страны) на витрине.
    | Канонические значения в БД — EN; переводы лежат в jsonb `*_i18n`.
    */
    'ui_locale' => env('MATCHIQ_UI_LOCALE', 'ru'),

    /*
    | ЗАГЛУШКА: hit-rate модели на главной (полоса «как считаем»). Захардкожено, пока нет реальной метрики:
    | заменить значением из resolved prediction_outcomes (is_hit) и убрать is_placeholder в BuildMatchesIndex.
    */
    'model_hit_rate_placeholder' => 99,

    /*
    | Правила групповых этапов по slug соревнования (попадают в `stages.rules` при загрузке структуры,
    | если у этапа правила ещё не заданы). Кубок России: победа 3, победа по пенальти 2, поражение по пенальти 1.
    */
    'stage_rules' => [
        'russian-cup' => [
            'points' => ['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1],
        ],
    ],
];
