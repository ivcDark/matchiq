<?php

namespace App\Http\Responses\Matches;

use App\Models\Team;
use Illuminate\Support\Collection;

final class MiniStandingsPayload
{
    /**
     * Окно таблицы вокруг двух команд матча (±1 место), с формой и очками за игру.
     *
     * @param  list<array<string, int>>  $table  результат LeagueTable::compute
     * @param  Collection<int, Team>  $teams
     * @param  array<int, list<string>>  $formByTeam  буквы формы по id команды
     * @return list<array<string, mixed>>
     */
    public static function make(array $table, int $homeTeamId, int $awayTeamId, Collection $teams, array $formByTeam): array
    {
        $focus = [];
        foreach ($table as $row) {
            if (in_array($row['team_id'], [$homeTeamId, $awayTeamId], true)) {
                $focus[] = $row['pos'];
            }
        }

        $min = $focus === [] ? 1 : max(1, min($focus) - 1);
        $max = $focus === [] ? min(count($table), 6) : min(count($table), max($focus) + 1);
        $locale = config('matchiq.ui_locale');

        $rows = [];
        foreach ($table as $row) {
            if ($row['pos'] < $min || $row['pos'] > $max) {
                continue;
            }
            $rows[] = [
                'pos' => $row['pos'],
                'team' => $teams[$row['team_id']]->localized('name', $locale),
                'played' => $row['played'],
                'wins' => $row['wins'],
                'draws' => $row['draws'],
                'gf' => $row['gf'],
                'ga' => $row['ga'],
                'gd' => $row['gd'],
                'pts' => $row['pts'],
                'ppg' => round($row['pts'] / max(1, $row['played']), 2),
                'form' => $formByTeam[$row['team_id']] ?? [],
                'highlight' => in_array($row['team_id'], [$homeTeamId, $awayTeamId], true),
            ];
        }

        return $rows;
    }
}
