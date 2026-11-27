<?php

namespace App\Repositories;

use App\Enums\MatchEventType;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Stage;
use App\Models\Team;
use Illuminate\Support\Collection;

/** Чтение данных страниц турнира и клуба (витрина, без записи). */
class TournamentCatalogRepository
{
    /**
     * Сезоны лиги: текущий первым, дальше свежие к старым.
     *
     * @return Collection<int, Season>
     */
    public function seasons(int $competitionId): Collection
    {
        return Season::query()
            ->where('competition_id', $competitionId)
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->orderByDesc('label')
            ->orderByDesc('id')
            ->get();
    }

    public function firstStage(int $seasonId): ?Stage
    {
        return Stage::query()->where('season_id', $seasonId)->orderBy('stage_order')->first();
    }

    /**
     * Все матчи сезона с командами и туром, по времени начала.
     *
     * @return Collection<int, FootballMatch>
     */
    public function matchesInSeason(int $seasonId): Collection
    {
        return FootballMatch::query()
            ->with(['homeTeam', 'awayTeam', 'round', 'currentPrediction'])
            ->where('season_id', $seasonId)
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Все матчи команды (любые турниры и сезоны), по времени начала.
     *
     * @return Collection<int, FootballMatch>
     */
    public function matchesForTeam(int $teamId): Collection
    {
        return FootballMatch::query()
            ->with(['homeTeam', 'awayTeam', 'round', 'competition'])
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get();
    }

    public function findTeam(int $id): ?Team
    {
        return Team::query()->with('country')->find($id);
    }

    /**
     * Голы и передачи игроков по событиям матчей (автоголы не считаются, передача — `related_player_id` гола).
     *
     * @param  list<int>  $matchIds
     * @return list<array{player_id: int, team_id: int, goals: int, pens: int, assists: int}>
     */
    public function goalContributions(array $matchIds, ?int $teamId = null): array
    {
        if ($matchIds === []) {
            return [];
        }

        $rows = [];
        $goalTypes = [MatchEventType::Goal, MatchEventType::PenaltyGoal];

        $goals = MatchEvent::query()
            ->selectRaw("player_id, team_id, count(*) as goals, sum(case when type = 'penalty_goal' then 1 else 0 end) as pens")
            ->whereIn('match_id', $matchIds)
            ->whereIn('type', $goalTypes)
            ->whereNotNull('player_id')
            ->whereNotNull('team_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->groupBy('player_id', 'team_id')
            ->get();

        foreach ($goals as $row) {
            $rows[$row->player_id.':'.$row->team_id] = [
                'player_id' => (int) $row->player_id, 'team_id' => (int) $row->team_id,
                'goals' => (int) $row->goals, 'pens' => (int) $row->pens, 'assists' => 0,
            ];
        }

        $assists = MatchEvent::query()
            ->selectRaw('related_player_id as player_id, team_id, count(*) as assists')
            ->whereIn('match_id', $matchIds)
            ->whereIn('type', $goalTypes)
            ->whereNotNull('related_player_id')
            ->whereNotNull('team_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->groupBy('related_player_id', 'team_id')
            ->get();

        foreach ($assists as $row) {
            $key = $row->player_id.':'.$row->team_id;
            $rows[$key] ??= [
                'player_id' => (int) $row->player_id, 'team_id' => (int) $row->team_id,
                'goals' => 0, 'pens' => 0, 'assists' => 0,
            ];
            $rows[$key]['assists'] = (int) $row->assists;
        }

        return array_values($rows);
    }

    /**
     * События команды в матчах: голы (передача — `related_player_id`), карточки и замены
     * (`player_id` уходит, `related_player_id` выходит на поле).
     *
     * @param  list<int>  $matchIds
     * @return Collection<int, MatchEvent>
     */
    public function teamEvents(int $teamId, array $matchIds): Collection
    {
        if ($matchIds === []) {
            return new Collection;
        }

        return MatchEvent::query()
            ->where('team_id', $teamId)
            ->whereIn('match_id', $matchIds)
            ->whereIn('type', [
                MatchEventType::Goal, MatchEventType::PenaltyGoal, MatchEventType::Yellow,
                MatchEventType::SecondYellow, MatchEventType::Red, MatchEventType::Substitution,
            ])
            ->get(['match_id', 'player_id', 'related_player_id', 'type']);
    }

    /**
     * Стартовые составы команды в матчах.
     *
     * @param  list<int>  $matchIds
     * @return Collection<int, MatchLineup>
     */
    public function starters(int $teamId, array $matchIds): Collection
    {
        if ($matchIds === []) {
            return new Collection;
        }

        return MatchLineup::query()
            ->where('team_id', $teamId)
            ->where('is_starter', true)
            ->whereIn('match_id', $matchIds)
            ->get(['match_id', 'player_id', 'position']);
    }

    /**
     * Число матчей в заявках игроков среди переданных матчей.
     *
     * @param  list<int>  $playerIds
     * @param  list<int>  $matchIds
     * @return array<int, int>
     */
    public function appearances(array $playerIds, array $matchIds): array
    {
        if ($playerIds === [] || $matchIds === []) {
            return [];
        }

        return MatchLineup::query()
            ->selectRaw('player_id, count(*) as apps')
            ->whereIn('player_id', $playerIds)
            ->whereIn('match_id', $matchIds)
            ->groupBy('player_id')
            ->pluck('apps', 'player_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Player>
     */
    public function playersByIds(array $ids): Collection
    {
        return Player::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Team>
     */
    public function teamsByIds(array $ids): Collection
    {
        return Team::whereIn('id', $ids)->get()->keyBy('id');
    }
}
