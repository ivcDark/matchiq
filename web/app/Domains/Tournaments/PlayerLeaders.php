<?php

namespace App\Domains\Tournaments;

/**
 * Четыре игровых списка страницы турнира из матчевой статистики игроков (`match_player_stats`):
 * «скорострельные» (Гол/90), вратари (сухие матчи на 90 минут), «больше всех на поле» и «лучшие по рейтингу».
 * Минимальных порогов по минутам и матчам нет (для «скорострельных» нужен хотя бы один гол, для вратарей — хотя бы один сухой матч); игрок, перешедший между командами, считается одной строкой (команда — где он играл дольше).
 */
final class PlayerLeaders
{
    public const LIMIT = 50;

    /** Вратарь сохранил ворота сухими, если сыграл не меньше этого числа минут и не пропустил. */
    public const CLEAN_SHEET_MIN_MINUTES = 60;

    /**
     * @param  list<array{player_id: int, team_id: int, minutes: int, matches: int, goals: int, rated: int, rating_sum: float}>  $totals  итоги по игроку и команде: все позиции
     * @param  list<array{player_id: int, team_id: int, minutes: int, goals_conceded: ?int}>  $keeperMatches  матчи вратарей с минутами на поле
     * @param  array<int, array{name: string, position: ?string}>  $players
     * @param  array<int, string>  $teams  название команды по id
     * @return array{fastScorers: list<array<string, mixed>>, goalkeepers: list<array<string, mixed>>, minutes: list<array<string, mixed>>, ratings: list<array<string, mixed>>}
     */
    public static function compute(array $totals, array $keeperMatches, array $players, array $teams): array
    {
        $merged = self::mergeByPlayer($totals);
        $identity = fn (array $row): array => [
            'name' => PlayerName::short($players[$row['player_id']]['name'] ?? ''),
            'team' => $teams[$row['team_id']] ?? '',
            'position' => $players[$row['player_id']]['position'] ?? null,
        ];

        $fast = [];
        $minutes = [];
        $ratings = [];
        foreach ($merged as $row) {
            if ($row['minutes'] > 0) {
                $minutes[] = $identity($row) + ['matches' => $row['matches'], 'minutes' => $row['minutes'], 'sort' => [-$row['minutes'], -$row['matches']]];
            }
            if ($row['minutes'] > 0 && $row['goals'] > 0) {
                $fast[] = $identity($row) + [
                    'minutes' => $row['minutes'],
                    'goals' => $row['goals'],
                    'goals_per_90' => round($row['goals'] * 90 / $row['minutes'], 2),
                    'minutes_per_goal' => (int) round($row['minutes'] / $row['goals']),
                    'sort' => [-($row['goals'] / $row['minutes']), -$row['goals']],
                ];
            }
            if ($row['rated'] > 0) {
                $average = round($row['rating_sum'] / $row['rated'], 2);
                $ratings[] = $identity($row) + ['matches' => $row['rated'], 'rating' => $average, 'sort' => [-$average, -$row['rated']]];
            }
        }

        return [
            'fastScorers' => self::ranked($fast, ['name', 'team', 'position', 'minutes', 'goals', 'goals_per_90', 'minutes_per_goal']),
            'goalkeepers' => self::goalkeepers($keeperMatches, $identity),
            'minutes' => self::ranked($minutes, ['name', 'team', 'position', 'matches', 'minutes']),
            'ratings' => self::ranked($ratings, ['name', 'team', 'position', 'matches', 'rating']),
        ];
    }

    /**
     * @param  list<array{player_id: int, team_id: int, minutes: int, matches: int, goals: int, rated: int, rating_sum: float}>  $totals
     * @return list<array{player_id: int, team_id: int, minutes: int, matches: int, goals: int, rated: int, rating_sum: float}>
     */
    private static function mergeByPlayer(array $totals): array
    {
        $byPlayer = [];
        foreach ($totals as $row) {
            $id = $row['player_id'];
            if (! isset($byPlayer[$id])) {
                $byPlayer[$id] = $row;

                continue;
            }
            $current = $byPlayer[$id];
            foreach (['minutes', 'matches', 'goals', 'rated', 'rating_sum'] as $key) {
                $byPlayer[$id][$key] = $current[$key] + $row[$key];
            }
            $byPlayer[$id]['team_id'] = $row['minutes'] > $current['minutes'] ? $row['team_id'] : $current['team_id'];
        }

        return array_values($byPlayer);
    }

    /**
     * Матчи без `goals_conceded` в данных источника не берём: сухой матч там определить нельзя, а минуты исказили бы Сух/90.
     *
     * @param  list<array{player_id: int, team_id: int, minutes: int, goals_conceded: ?int}>  $keeperMatches
     * @return list<array<string, mixed>>
     */
    private static function goalkeepers(array $keeperMatches, callable $identity): array
    {
        $byPlayer = [];
        foreach ($keeperMatches as $m) {
            if ($m['goals_conceded'] === null || $m['minutes'] <= 0) {
                continue;
            }
            $acc = &$byPlayer[$m['player_id']];
            $acc ??= ['player_id' => $m['player_id'], 'team_id' => $m['team_id'], 'minutes' => 0, 'clean_sheets' => 0, 'team_minutes' => []];
            $acc['minutes'] += $m['minutes'];
            $acc['team_minutes'][$m['team_id']] = ($acc['team_minutes'][$m['team_id']] ?? 0) + $m['minutes'];
            if ($m['goals_conceded'] === 0 && $m['minutes'] >= self::CLEAN_SHEET_MIN_MINUTES) {
                $acc['clean_sheets']++;
            }
            unset($acc);
        }

        $items = [];
        foreach ($byPlayer as $row) {
            if ($row['clean_sheets'] < 1) {
                continue;
            }
            arsort($row['team_minutes']);
            $row['team_id'] = array_key_first($row['team_minutes']);
            $items[] = $identity($row) + [
                'minutes' => $row['minutes'],
                'clean_sheets' => $row['clean_sheets'],
                'clean_sheets_per_90' => round($row['clean_sheets'] * 90 / $row['minutes'], 2),
                'minutes_per_clean_sheet' => (int) round($row['minutes'] / $row['clean_sheets']),
                'sort' => [-($row['clean_sheets'] / $row['minutes']), -$row['clean_sheets'], -$row['minutes']],
            ];
        }

        return self::ranked($items, ['name', 'team', 'position', 'minutes', 'clean_sheets', 'clean_sheets_per_90', 'minutes_per_clean_sheet']);
    }

    /**
     * @param  list<array<string, mixed>>  $items  у каждой строки `sort` — ключ сортировки по возрастанию; дальше по имени
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function ranked(array $items, array $keys): array
    {
        usort($items, fn (array $a, array $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);

        $out = [];
        foreach (array_slice($items, 0, self::LIMIT) as $i => $item) {
            $out[] = ['rank' => $i + 1] + array_intersect_key($item, array_flip($keys));
        }

        return $out;
    }
}
