<?php

namespace App\Domains\Tournaments;

/**
 * Топ-5 команд по средним матчевым показателям («Лучшие команды»): владение, xG, удары, точность передач и рейтинг —
 * среднее за матч по матчам, где значение есть; агрессивность — сумма карточек.
 * Выигранной борьбы в воздухе в данных источника нет (колонка `aerials_won` не заполняется), поэтому вместо неё xG.
 */
final class TeamStatsLeaders
{
    public const LIMIT = 5;

    public const CONTEXTS = ['overall', 'home', 'away'];

    public const TABLES = ['possession', 'aggression', 'xg', 'shots', 'passing', 'rating'];

    /**
     * @param  list<array{team_id: int, side: 'home'|'away', possession_pct: ?float, xg: ?float, shots: ?int, pass_accuracy_pct: ?float, yellow_cards: ?int, red_cards: ?int, rating: ?float}>  $rows  по строке на команду и матч (период «весь матч»)
     * @param  array<int, string>  $names  название команды по id
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public static function compute(array $rows, array $names): array
    {
        $out = [];
        foreach (self::CONTEXTS as $context) {
            $scoped = $context === 'overall'
                ? $rows
                : array_values(array_filter($rows, fn (array $r) => $r['side'] === $context));
            $out[$context] = self::tables(self::aggregate($scoped), $names);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, array{sum: float, n: int}>>
     */
    private static function aggregate(array $rows): array
    {
        $acc = [];
        $add = function (int $teamId, string $key, mixed $value) use (&$acc): void {
            if ($value === null) {
                return;
            }
            $acc[$teamId][$key] ??= ['sum' => 0.0, 'n' => 0];
            $acc[$teamId][$key]['sum'] += (float) $value;
            $acc[$teamId][$key]['n']++;
        };

        foreach ($rows as $r) {
            $teamId = $r['team_id'];
            $add($teamId, 'possession', $r['possession_pct']);
            $add($teamId, 'xg', $r['xg']);
            $add($teamId, 'shots', $r['shots']);
            $add($teamId, 'passing', $r['pass_accuracy_pct']);
            $add($teamId, 'rating', $r['rating']);

            // Источник не присылает нулевые карточки: у матча с владением и без карточек это 0, а не «нет данных».
            if ($r['possession_pct'] !== null || $r['yellow_cards'] !== null || $r['red_cards'] !== null) {
                $add($teamId, 'yellow', $r['yellow_cards'] ?? 0);
                $add($teamId, 'red', $r['red_cards'] ?? 0);
            }
        }

        return $acc;
    }

    /**
     * @param  array<int, array<string, array{sum: float, n: int}>>  $acc
     * @param  array<int, string>  $names
     * @return array<string, list<array<string, mixed>>>
     */
    private static function tables(array $acc, array $names): array
    {
        $avg = fn (int $teamId, string $key): ?float => isset($acc[$teamId][$key]) ? $acc[$teamId][$key]['sum'] / $acc[$teamId][$key]['n'] : null;

        $byAverage = fn (string $key, string $field): array => self::ranked(
            array_filter(array_map(fn (int $teamId) => $avg($teamId, $key) === null ? null : [
                'team_id' => $teamId, $field => round($avg($teamId, $key), 2),
            ], array_keys($acc))),
            [$field],
            $names,
        );

        $aggression = [];
        foreach ($acc as $teamId => $metrics) {
            if (isset($metrics['yellow'])) {
                $aggression[] = [
                    'team_id' => $teamId,
                    'yellow' => (int) $metrics['yellow']['sum'],
                    'red' => (int) ($metrics['red']['sum'] ?? 0),
                ];
            }
        }

        return [
            'possession' => $byAverage('possession', 'possession'),
            'aggression' => self::ranked($aggression, ['yellow', 'red'], $names),
            'xg' => $byAverage('xg', 'xg'),
            'shots' => $byAverage('shots', 'shots'),
            'passing' => $byAverage('passing', 'passing'),
            'rating' => $byAverage('rating', 'rating'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $items
     * @param  list<string>  $sortKeys  по убыванию; дальше — по названию команды
     * @param  array<int, string>  $names
     * @return list<array<string, mixed>>
     */
    private static function ranked(array $items, array $sortKeys, array $names): array
    {
        $items = array_values(array_filter($items));
        usort($items, function (array $a, array $b) use ($sortKeys, $names): int {
            foreach ($sortKeys as $key) {
                if (($c = $b[$key] <=> $a[$key]) !== 0) {
                    return $c;
                }
            }

            return [$names[$a['team_id']] ?? '', $a['team_id']] <=> [$names[$b['team_id']] ?? '', $b['team_id']];
        });

        $out = [];
        foreach (array_slice($items, 0, self::LIMIT) as $i => $item) {
            $team = $item['team_id'];
            unset($item['team_id']);
            $out[] = ['rank' => $i + 1, 'team' => $names[$team] ?? '', 'team_id' => $team] + $item;
        }

        return $out;
    }
}
