<?php

namespace App\Repositories;

use App\Enums\MatchSide;
use App\Enums\UpsertOutcome;
use App\Models\MatchTeam;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Support\Collection;

class MatchTeamRepository
{
    use UpsertsModels;

    /** @return Collection<string, MatchTeam> ключ — сторона (home|away) */
    public function forMatch(int $matchId): Collection
    {
        return MatchTeam::where('match_id', $matchId)->get()->keyBy(fn (MatchTeam $t) => $t->side->value);
    }

    /** @return array{0: MatchTeam, 1: UpsertOutcome} */
    public function upsert(?MatchTeam $existing, int $matchId, int $teamId, MatchSide $side, ?int $coachId, ?string $formation, ?float $rating): array
    {
        return $this->persist(new MatchTeam, $existing, [
            'match_id' => $matchId,
            'team_id' => $teamId,
            'side' => $side,
            'coach_id' => $coachId,
            'formation' => $formation,
            'rating' => $rating,
        ]);
    }

    /** @param  list<int>  $ids */
    public function delete(array $ids): void
    {
        MatchTeam::whereIn('id', $ids)->delete();
    }
}
