<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\StageGroup;
use App\Repositories\Concerns\UpsertsModels;

class StageGroupRepository
{
    use UpsertsModels;

    public function findByName(int $stageId, string $name): ?StageGroup
    {
        return StageGroup::where('stage_id', $stageId)->where('name', $name)->first();
    }

    /** @return array{0: StageGroup, 1: UpsertOutcome} */
    public function upsert(int $stageId, string $name, int $order): array
    {
        return $this->persist(new StageGroup, $this->findByName($stageId, $name), [
            'stage_id' => $stageId,
            'name' => $name,
            'group_order' => $order,
        ]);
    }
}
