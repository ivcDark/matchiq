<?php

namespace App\Domains\Matches;

use App\Enums\MatchResult;
use App\Models\FootballMatch;
use Illuminate\Support\Collection;

/**
 * Турнирная таблица, посчитанная по сыгранным матчам сезона (по умолчанию 3-1-0, тай-брейки: разница, забитые).
 * Очки за победу/ничью/пенальти задаёт `StandingRules` (из `stages.rules`); колонки В/Н/П считаются по счёту матча.
 * Пока таблица `standings` не заполняется загрузкой, это единственный источник мест в лиге.
 */
final class LeagueTable
{
    private const FORM_LENGTH = 5;

    /**
     * @param  Collection<int, FootballMatch>  $finished  завершённые матчи одного сезона со счётом
     * @param  'home'|'away'|null  $side  только домашние или только гостевые матчи команд; null — все
     * @param  StandingRules|null  $rules  очки за результат; null — 3-1-0
     * @return list<array{team_id: int, pos: int, played: int, wins: int, draws: int, losses: int, gf: int, ga: int, gd: int, pts: int, form: list<string>}>
     */
    public static function compute(Collection $finished, ?string $side = null, ?StandingRules $rules = null): array
    {
        $rules ??= new StandingRules;
        $rows = [];

        $chronological = $finished->sortBy(
            fn (FootballMatch $m) => [$m->kickoff_at?->getTimestamp() ?? 0, $m->id],
        );

        foreach ($chronological as $match) {
            if ($match->home_goals === null || $match->away_goals === null) {
                continue;
            }

            foreach ([
                ['home', $match->home_team_id, $match->home_goals, $match->away_goals, $match->home_pens, $match->away_pens],
                ['away', $match->away_team_id, $match->away_goals, $match->home_goals, $match->away_pens, $match->home_pens],
            ] as [$venue, $teamId, $for, $against, $pensFor, $pensAgainst]) {
                if ($side !== null && $side !== $venue) {
                    continue;
                }

                $rows[$teamId] ??= [
                    'team_id' => $teamId, 'pos' => 0, 'played' => 0, 'wins' => 0, 'draws' => 0,
                    'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0, 'form' => [],
                ];

                $row = &$rows[$teamId];
                $row['played']++;
                $row['gf'] += $for;
                $row['ga'] += $against;
                $row['pts'] += $rules->points($for, $against, $pensFor, $pensAgainst);
                match (true) {
                    $for > $against => $row['wins']++,
                    $for === $against => $row['draws']++,
                    default => $row['losses']++,
                };
                $row['form'][] = MatchResult::fromGoals($for, $against)->value;
                unset($row);
            }
        }

        foreach ($rows as &$row) {
            $row['gd'] = $row['gf'] - $row['ga'];
            $row['form'] = array_slice($row['form'], -self::FORM_LENGTH);
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
