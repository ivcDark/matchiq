<?php

namespace App\Repositories;

use App\Enums\StatsPeriod;
use App\Enums\UpsertOutcome;
use App\Models\MatchPlayerStat;
use App\Models\MatchTeamStat;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Support\Collection;

/** Командная и игровая статистика матча. */
class MatchStatsRepository
{
    use UpsertsModels;

    /** @return Collection<string, MatchTeamStat> ключ — «team_id:period» */
    public function teamStatsForMatch(int $matchId): Collection
    {
        return MatchTeamStat::where('match_id', $matchId)->get()
            ->keyBy(fn (MatchTeamStat $s) => $s->team_id.':'.$s->period->value);
    }

    /** @return Collection<int, MatchPlayerStat> ключ — player_id */
    public function playerStatsForMatch(int $matchId): Collection
    {
        return MatchPlayerStat::where('match_id', $matchId)->get()->keyBy('player_id');
    }

    /**
     * @param  array<string, mixed>  $metrics  Колонки match_team_stats (xg, xga, shots, … extra).
     * @return array{0: MatchTeamStat, 1: UpsertOutcome}
     */
    public function upsertTeamStat(?MatchTeamStat $existing, int $matchId, int $teamId, StatsPeriod $period, array $metrics): array
    {
        return $this->persist(new MatchTeamStat, $existing, [
            'match_id' => $matchId,
            'team_id' => $teamId,
            'period' => $period,
        ] + $metrics);
    }

    /**
     * @param  array<string, mixed>  $metrics  Колонки match_player_stats (minutes, goals, xg, rating, is_motm, extra…).
     * @return array{0: MatchPlayerStat, 1: UpsertOutcome}
     */
    public function upsertPlayerStat(?MatchPlayerStat $existing, int $matchId, int $teamId, int $playerId, array $metrics): array
    {
        return $this->persist(new MatchPlayerStat, $existing, [
            'match_id' => $matchId,
            'team_id' => $teamId,
            'player_id' => $playerId,
        ] + $metrics);
    }

    /** @param  list<int>  $ids */
    public function deleteTeamStats(array $ids): void
    {
        MatchTeamStat::whereIn('id', $ids)->delete();
    }

    /** @param  list<int>  $ids */
    public function deletePlayerStats(array $ids): void
    {
        MatchPlayerStat::whereIn('id', $ids)->delete();
    }
}
