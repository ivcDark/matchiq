<?php

namespace App\Domains\Predictions;

/**
 * Эвристическая модель MVP (MFP-5): Пуассон по ожидаемым голам из силы атаки/защиты команд
 * (со сжатием к средним по лиге при малой выборке) + Пуассон по тоталам углов, карточек.
 * Чистая логика без БД: на вход профили команд, на выход вероятности исходов 0–1.
 */
final class HeuristicModel
{
    /** Сколько «виртуальных» игр средней команды подмешиваем к реальной выборке. */
    private const SHRINK_GAMES = 6;

    private const GOAL_GRID = 8;

    private const MIN_PROB = 0.01;

    private const MAX_PROB = 0.99;

    private const TOP_SCORELINES = 8;

    /**
     * @return array{
     *     expected_home: float, expected_away: float, p_home: float, p_draw: float, p_away: float,
     *     outcomes: list<array{market: string, outcome: string, line: float|null, probability: float}>,
     *     scorelines: list<array{home: int, away: int, probability: float}>
     * }
     */
    public function predict(TeamProfile $home, TeamProfile $away, LeagueBaseline $league): array
    {
        [$lambdaHome, $lambdaAway] = $this->expectedGoals($home, $away, $league);
        $grid = $this->scoreGrid($lambdaHome, $lambdaAway);

        $pHome = $pDraw = $pAway = $pBtts = 0.0;
        $goalsDist = [];
        foreach ($grid as $h => $row) {
            foreach ($row as $a => $p) {
                match (true) {
                    $h > $a => $pHome += $p,
                    $h < $a => $pAway += $p,
                    default => $pDraw += $p,
                };
                if ($h > 0 && $a > 0) {
                    $pBtts += $p;
                }
                $goalsDist[$h + $a] = ($goalsDist[$h + $a] ?? 0.0) + $p;
            }
        }

        $outcomes = [];
        $add = function (string $market, array $probs, ?float $line = null) use (&$outcomes): void {
            foreach ($probs as $key => $p) {
                $outcomes[] = ['market' => $market, 'outcome' => $key, 'line' => $line, 'probability' => $this->clamp($p)];
            }
        };

        $add(MarketCatalog::RESULT, ['home' => $pHome, 'draw' => $pDraw, 'away' => $pAway]);
        $add(MarketCatalog::DOUBLE_CHANCE, ['1x' => $pHome + $pDraw, '12' => $pHome + $pAway, 'x2' => $pDraw + $pAway]);
        $add(MarketCatalog::BTTS, ['yes' => $pBtts, 'no' => 1 - $pBtts]);

        foreach ($this->lines(MarketCatalog::TOTAL_GOALS) as $line) {
            $over = $this->overProbability($goalsDist, $line);
            $add(MarketCatalog::TOTAL_GOALS, ['over' => $over, 'under' => 1 - $over], $line);
        }

        $corners = $this->poissonMean($home->corners, $away->corners, $home->statGames, $away->statGames, $league->corners);
        foreach ($this->lines(MarketCatalog::TOTAL_CORNERS) as $line) {
            $over = $this->overProbability($this->poissonDist($corners, 30), $line);
            $add(MarketCatalog::TOTAL_CORNERS, ['over' => $over, 'under' => 1 - $over], $line);
        }

        $yellows = $this->poissonMean($home->yellows, $away->yellows, $home->statGames, $away->statGames, $league->yellows);
        foreach ($this->lines(MarketCatalog::TOTAL_YELLOWS) as $line) {
            $over = $this->overProbability($this->poissonDist($yellows, 16), $line);
            $add(MarketCatalog::TOTAL_YELLOWS, ['over' => $over, 'under' => 1 - $over], $line);
        }

        $reds = $this->poissonMean($home->reds, $away->reds, $home->statGames, $away->statGames, $league->reds);
        $pRed = 1 - exp(-$reds);
        $add(MarketCatalog::RED_CARD, ['yes' => $pRed, 'no' => 1 - $pRed]);

        return [
            'expected_home' => round($lambdaHome, 3),
            'expected_away' => round($lambdaAway, 3),
            'p_home' => $this->clamp($pHome),
            'p_draw' => $this->clamp($pDraw),
            'p_away' => $this->clamp($pAway),
            'outcomes' => $outcomes,
            'scorelines' => $this->topScorelines($grid),
        ];
    }

    /** @return array{0: float, 1: float} */
    private function expectedGoals(TeamProfile $home, TeamProfile $away, LeagueBaseline $league): array
    {
        $avgHome = max(0.3, $league->homeGoals);
        $avgAway = max(0.3, $league->awayGoals);
        $avgTeam = ($avgHome + $avgAway) / 2;

        $homeAttack = $this->strength($home->goalsFor, $home->games, $avgTeam);
        $homeDefence = $this->strength($home->goalsAgainst, $home->games, $avgTeam);
        $awayAttack = $this->strength($away->goalsFor, $away->games, $avgTeam);
        $awayDefence = $this->strength($away->goalsAgainst, $away->games, $avgTeam);

        $lambdaHome = $avgHome * $homeAttack * $awayDefence;
        $lambdaAway = $avgAway * $awayAttack * $homeDefence;

        return [max(0.2, min(4.5, $lambdaHome)), max(0.2, min(4.5, $lambdaAway))];
    }

    /** Относительная сила (1.0 = средняя команда лиги), сжатая к 1.0 при малой выборке. */
    private function strength(float $perGame, int $games, float $leagueAvg): float
    {
        if ($games <= 0 || $leagueAvg <= 0) {
            return 1.0;
        }

        $ratio = $perGame / $leagueAvg;

        return ($ratio * $games + self::SHRINK_GAMES) / ($games + self::SHRINK_GAMES);
    }

    /** Ожидаемый тотал матча по двум командам со сжатием к базе лиги. */
    private function poissonMean(float $homeValue, float $awayValue, int $homeGames, int $awayGames, float $base): float
    {
        $games = $homeGames + $awayGames;
        if ($games <= 0) {
            return $base;
        }

        $observed = ($homeValue * $homeGames + $awayValue * $awayGames) / $games;

        return max(0.05, ($observed * $games + $base * self::SHRINK_GAMES) / ($games + self::SHRINK_GAMES));
    }

    /** @return array<int, array<int, float>> */
    private function scoreGrid(float $lambdaHome, float $lambdaAway): array
    {
        $homeDist = $this->poissonDist($lambdaHome, self::GOAL_GRID);
        $awayDist = $this->poissonDist($lambdaAway, self::GOAL_GRID);

        $grid = [];
        $sum = 0.0;
        foreach ($homeDist as $h => $ph) {
            foreach ($awayDist as $a => $pa) {
                $grid[$h][$a] = $ph * $pa;
                $sum += $ph * $pa;
            }
        }

        foreach ($grid as $h => $row) {
            foreach ($row as $a => $p) {
                $grid[$h][$a] = $p / $sum;
            }
        }

        return $grid;
    }

    /** @return array<int, float> */
    private function poissonDist(float $mean, int $max): array
    {
        $dist = [];
        $p = exp(-$mean);
        $sum = 0.0;
        for ($k = 0; $k <= $max; $k++) {
            $dist[$k] = $p;
            $sum += $p;
            $p *= $mean / ($k + 1);
        }

        foreach ($dist as $k => $v) {
            $dist[$k] = $v / $sum;
        }

        return $dist;
    }

    /** @param  array<int, float>  $dist */
    private function overProbability(array $dist, float $line): float
    {
        $over = 0.0;
        foreach ($dist as $k => $p) {
            if ($k > $line) {
                $over += $p;
            }
        }

        return $over;
    }

    /**
     * @param  array<int, array<int, float>>  $grid
     * @return list<array{home: int, away: int, probability: float}>
     */
    private function topScorelines(array $grid): array
    {
        $rows = [];
        foreach ($grid as $h => $row) {
            foreach ($row as $a => $p) {
                $rows[] = ['home' => $h, 'away' => $a, 'probability' => round($p, 4)];
            }
        }

        usort($rows, fn (array $x, array $y) => $y['probability'] <=> $x['probability']);

        return array_slice($rows, 0, self::TOP_SCORELINES);
    }

    /** @return list<float> */
    private function lines(string $code): array
    {
        foreach (MarketCatalog::definitions() as $definition) {
            if ($definition['code'] === $code) {
                return $definition['lines'];
            }
        }

        return [];
    }

    private function clamp(float $p): float
    {
        return round(max(self::MIN_PROB, min(self::MAX_PROB, $p)), 4);
    }
}
