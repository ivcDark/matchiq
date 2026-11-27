<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\FootballMatch;
use App\Models\StageTeam;
use App\Repositories\Concerns\UpsertsModels;

class StageTeamRepository
{
    use UpsertsModels;

    public function find(int $stageId, int $teamId): ?StageTeam
    {
        return StageTeam::where('stage_id', $stageId)->where('team_id', $teamId)->first();
    }

    public function groupIdOf(int $stageId, int $teamId): ?int
    {
        $id = StageTeam::where('stage_id', $stageId)->where('team_id', $teamId)->value('stage_group_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{0: StageTeam, 1: UpsertOutcome} */
    public function upsert(int $stageId, int $teamId, ?int $groupId): array
    {
        return $this->persist(new StageTeam, $this->find($stageId, $teamId), [
            'stage_id' => $stageId,
            'team_id' => $teamId,
            'stage_group_id' => $groupId,
        ]);
    }

    /** Матчи стадии с участием команды, уже загруженные до появления групп, получают группу команды. */
    public function assignGroupToMatches(int $stageId, int $teamId, int $groupId): void
    {
        FootballMatch::where('stage_id', $stageId)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->where(fn ($q) => $q->whereNull('stage_group_id')->orWhere('stage_group_id', '!=', $groupId))
            ->update(['stage_group_id' => $groupId]);
    }
}
