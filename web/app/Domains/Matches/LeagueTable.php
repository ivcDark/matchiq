<?php

namespace App\Domains\Matches;

use App\Models\FootballMatch;
use Illuminate\Support\Collection;

/**
 * Турнирная таблица, посчитанная по сыгранным матчам сезона (3-1-0, тай-брейки: разница, забитые).
 * Пока таблица `standings` не заполняется загрузкой, это единственный источник мест в лиге.
 */
final class LeagueTable
{
    /**
     * @param  Collection<int, FootballMatch>  $finished  завершённые матчи одного сезона со счётом
     * @return list<array{team_id: int, pos: int, played: int, wins: int, draws: int, losses: int, gf: int, ga: int, gd: int, pts: int}>
     */
    public static function compute(Collection $finished): array
    {
        $rows = [];

        foreach ($finished as $match) {
            if ($match->home_goals === null || $match->away_goals === null) {
                continue;
            }

            foreach ([
                [$match->home_team_id, $match->home_goals, $match->away_goals],
                [$match->away_team_id, $match->away_goals, $match->home_goals],
            ] as [$teamId, $for, $against]) {
                $rows[$teamId] ??= [
                    'team_id' => $teamId, 'pos' => 0, 'played' => 0, 'wins' => 0, 'draws' => 0,
                    'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0,
                ];

                $row = &$rows[$teamId];
                $row['played']++;
                $row['gf'] += $for;
                $row['ga'] += $against;
                match (true) {
                    $for > $against => [$row['wins']++, $row['pts'] += 3],
                    $for === $against => [$row['draws']++, $row['pts']++],
                    default => $row['losses']++,
                };
                unset($row);
            }
        }

        foreach ($rows as &$row) {
            $row['gd'] = $row['gf'] - $row['ga'];
        }
        unset($row);

        $sorted = array_values($rows);
        usort($sorted, static fn (array $a, array $b) => [$b['pts'], $b['gd'], $b['gf'], $a['team_id']]
            <=> [$a['pts'], $a['gd'], $a['gf'], $b['team_id']]);

        foreach ($sorted as $i => &$row) {
            $row['pos'] = $i + 1;
        }
        unset($row);

        return $sorted;
    }
}
