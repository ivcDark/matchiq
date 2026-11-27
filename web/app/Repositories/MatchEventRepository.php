<?php

namespace App\Repositories;

use App\Enums\MatchEventType;
use App\Enums\UpsertOutcome;
use App\Models\MatchEvent;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Support\Collection;

class MatchEventRepository
{
    use UpsertsModels;

    /** @return Collection<int, MatchEvent> ключ — sort_order источника */
    public function forMatch(int $matchId): Collection
    {
        return MatchEvent::where('match_id', $matchId)->get()->keyBy('sort_order');
    }

    /** @return array{0: MatchEvent, 1: UpsertOutcome} */
    public function upsert(
        ?MatchEvent $existing,
        int $matchId,
        ?int $teamId,
        ?int $playerId,
        ?int $relatedPlayerId,
        MatchEventType $type,
        int $minute,
        ?int $addedMinute,
        ?int $scoreHome,
        ?int $scoreAway,
        int $sortOrder,
        ?array $details,
    ): array {
        return $this->persist(new MatchEvent, $existing, [
            'match_id' => $matchId,
            'team_id' => $teamId,
            'player_id' => $playerId,
            'related_player_id' => $relatedPlayerId,
            'type' => $type,
            'minute' => $minute,
            'added_minute' => $addedMinute,
            'score_home' => $scoreHome,
            'score_away' => $scoreAway,
            'sort_order' => $sortOrder,
            'details' => $details,
        ]);
    }

    /** @param  list<int>  $ids */
    public function delete(array $ids): void
    {
        MatchEvent::whereIn('id', $ids)->delete();
    }
}
