<?php

namespace App\Domains\Predictions;

/** Сверка исхода рынка с фактами матча: true — зашло, false — мимо, null — не хватает данных. */
final class OutcomeEvaluator
{
    public static function isHit(string $market, string $outcome, ?float $line, MatchFacts $facts): ?bool
    {
        return match ($market) {
            MarketCatalog::RESULT => self::result($outcome, $facts),
            MarketCatalog::DOUBLE_CHANCE => self::doubleChance($outcome, $facts),
            MarketCatalog::BTTS => self::yesNo($outcome, $facts->homeGoals > 0 && $facts->awayGoals > 0),
            MarketCatalog::TOTAL_GOALS => self::overUnder($outcome, $line, $facts->homeGoals + $facts->awayGoals),
            MarketCatalog::TOTAL_CORNERS => self::overUnder($outcome, $line, $facts->corners),
            MarketCatalog::TOTAL_YELLOWS => self::overUnder($outcome, $line, $facts->yellows),
            MarketCatalog::RED_CARD => $facts->reds === null ? null : self::yesNo($outcome, $facts->reds > 0),
            default => null,
        };
    }

    private static function result(string $outcome, MatchFacts $facts): ?bool
    {
        $actual = match (true) {
            $facts->homeGoals > $facts->awayGoals => 'home',
            $facts->homeGoals < $facts->awayGoals => 'away',
            default => 'draw',
        };

        return in_array($outcome, ['home', 'draw', 'away'], true) ? $outcome === $actual : null;
    }

    private static function doubleChance(string $outcome, MatchFacts $facts): ?bool
    {
        $diff = $facts->homeGoals <=> $facts->awayGoals;

        return match ($outcome) {
            '1x' => $diff >= 0,
            '12' => $diff !== 0,
            'x2' => $diff <= 0,
            default => null,
        };
    }

    private static function yesNo(string $outcome, bool $happened): ?bool
    {
        return match ($outcome) {
            'yes' => $happened,
            'no' => ! $happened,
            default => null,
        };
    }

    private static function overUnder(string $outcome, ?float $line, ?int $total): ?bool
    {
        if ($line === null || $total === null) {
            return null;
        }

        return match ($outcome) {
            'over' => $total > $line,
            'under' => $total < $line,
            default => null,
        };
    }
}
