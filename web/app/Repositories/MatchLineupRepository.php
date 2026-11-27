<?php

namespace App\Repositories;

use App\Enums\PlayerPosition;
use App\Enums\UpsertOutcome;
use App\Models\MatchLineup;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Support\Collection;

class MatchLineupRepository
{
    use UpsertsModels;

    /** @return Collection<int, MatchLineup> ключ — player_id */
    public function forMatch(int $matchId): Collection
    {
        return MatchLineup::where('match_id', $matchId)->get()->keyBy('player_id');
    }

    /** @return array{0: MatchLineup, 1: UpsertOutcome} */
    public function upsert(
        ?MatchLineup $existing,
        int $matchId,
        int $teamId,
        int $playerId,
        bool $isStarter,
        bool $isCaptain,
        ?int $shirtNumber,
        ?PlayerPosition $position,
        ?string $line,
        int $sortOrder,
        ?int $minuteIn,
        ?int $minuteOut,
    ): array {
        return $this->persist(new MatchLineup, $existing, [
            'match_id' => $matchId,
            'team_id' => $teamId,
            'player_id' => $playerId,
            'is_starter' => $isStarter,
            'is_captain' => $isCaptain,
            'shirt_number' => $shirtNumber,
            'position' => $position,
            'line' => $line,
            'sort_order' => $sortOrder,
            'minute_in' => $minuteIn,
            'minute_out' => $minuteOut,
        ]);
    }

    /** @param  list<int>  $ids */
    public function delete(array $ids): void
    {
        MatchLineup::whereIn('id', $ids)->delete();
    }
}
