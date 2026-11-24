<?php

namespace App\Support;

/**
 * Правила очков рейтинга (docs/project-context.md, п. 5.1 и «Принятые решения»):
 * points = round(base × (1 − p/100) × multiplier), base = 100, multiplier = 1; промах — 0.
 */
class RatingRules
{
    public const BASE = 100;

    public const MULTIPLIER = 1;

    /**
     * Очки за угаданное событие при вероятности модели $probability (0–100) на момент выбора.
     */
    public static function points(int $probability): int
    {
        $probability = max(0, min(100, $probability));

        return (int) round(self::BASE * (1 - $probability / 100) * self::MULTIPLIER);
    }
}
