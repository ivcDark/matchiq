<?php

namespace App\Domains\Predictions;

/**
 * Средние показатели команды за последние матчи (на матч, во всех турнирах).
 * Статистика углов/карточек может отсутствовать — тогда `statGames` = 0 и эвристика берёт базовые значения.
 */
final readonly class TeamProfile
{
    public function __construct(
        public int $games = 0,
        public float $goalsFor = 0.0,
        public float $goalsAgainst = 0.0,
        public int $statGames = 0,
        public float $corners = 0.0,
        public float $yellows = 0.0,
        public float $reds = 0.0,
    ) {}
}
