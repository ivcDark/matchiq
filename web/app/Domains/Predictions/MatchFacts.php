<?php

namespace App\Domains\Predictions;

/** Итоговые факты матча для сверки исходов; null — данных нет, исход пока не резолвится. */
final readonly class MatchFacts
{
    public function __construct(
        public int $homeGoals,
        public int $awayGoals,
        public ?int $corners = null,
        public ?int $yellows = null,
        public ?int $reds = null,
    ) {}
}
