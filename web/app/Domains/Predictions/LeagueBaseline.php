<?php

namespace App\Domains\Predictions;

/** Средние по лиге: опорные значения для силы атаки/защиты и дефолты, если данных мало. */
final readonly class LeagueBaseline
{
    public const DEFAULT_HOME_GOALS = 1.45;

    public const DEFAULT_AWAY_GOALS = 1.15;

    public const DEFAULT_CORNERS = 9.8;

    public const DEFAULT_YELLOWS = 4.0;

    public const DEFAULT_REDS = 0.12;

    public function __construct(
        public float $homeGoals = self::DEFAULT_HOME_GOALS,
        public float $awayGoals = self::DEFAULT_AWAY_GOALS,
        public float $corners = self::DEFAULT_CORNERS,
        public float $yellows = self::DEFAULT_YELLOWS,
        public float $reds = self::DEFAULT_REDS,
    ) {}
}
