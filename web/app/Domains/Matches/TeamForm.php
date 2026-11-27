<?php

namespace App\Domains\Matches;

use App\Enums\MatchResult;
use App\Models\FootballMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Форма команд по сыгранным матчам: результаты с точки зрения команды, от старых к свежим.
 */
final class TeamForm
{
    /** @var array<int, list<FootballMatch>> матчи команды по возрастанию времени */
    private array $byTeam = [];

    /** @param  Collection<int, FootballMatch>  $finished  завершённые матчи со счётом */
    public function __construct(Collection $finished)
    {
        foreach ($finished->sortBy(fn (FootballMatch $m) => [$m->kickoff_at->getTimestamp(), $m->id]) as $match) {
            if ($match->home_goals === null || $match->away_goals === null) {
                continue;
            }
            $this->byTeam[$match->home_team_id][] = $match;
            $this->byTeam[$match->away_team_id][] = $match;
        }
    }

    /** @return list<MatchResult> */
    public function results(int $teamId, ?CarbonInterface $before = null, int $limit = 5): array
    {
        $matches = $this->recent($teamId, $before, $limit);

        return array_map(fn (FootballMatch $m) => $this->resultFor($m, $teamId), $matches);
    }

    /**
     * Последние матчи команды (до даты, если задана), от старых к свежим.
     *
     * @param  callable(FootballMatch): bool|null  $filter
     * @return list<FootballMatch>
     */
    public function recent(int $teamId, ?CarbonInterface $before = null, int $limit = 5, ?callable $filter = null): array
    {
        $matches = array_filter(
            $this->byTeam[$teamId] ?? [],
            fn (FootballMatch $m) => ($before === null || $m->kickoff_at->lt($before)) && ($filter === null || $filter($m)),
        );

        return array_values(array_slice($matches, -$limit));
    }

    /**
     * Последние личные встречи двух команд до даты, от старых к свежим.
     *
     * @return list<FootballMatch>
     */
    public function headToHead(int $teamA, int $teamB, CarbonInterface $before, int $limit = 5): array
    {
        return $this->recent(
            $teamA,
            $before,
            $limit,
            fn (FootballMatch $m) => in_array($teamB, [$m->home_team_id, $m->away_team_id], true),
        );
    }

    public function resultFor(FootballMatch $match, int $teamId): MatchResult
    {
        $isHome = $match->home_team_id === $teamId;

        return MatchResult::fromGoals(
            $isHome ? $match->home_goals : $match->away_goals,
            $isHome ? $match->away_goals : $match->home_goals,
        );
    }
}
