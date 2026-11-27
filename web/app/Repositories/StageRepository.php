<?php

namespace App\Repositories;

use App\Enums\StageType;
use App\Enums\UpsertOutcome;
use App\Models\Stage;
use App\Repositories\Concerns\UpsertsModels;

class StageRepository
{
    use UpsertsModels;

    public function find(int $id): ?Stage
    {
        return Stage::find($id);
    }

    /** Стадия сезона по нашему id или названию (без учёта регистра). */
    public function findInSeason(int $seasonId, string $idOrName): ?Stage
    {
        return Stage::where('season_id', $seasonId)
            ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($idOrName)])
                ->when(ctype_digit($idOrName), fn ($q) => $q->orWhere('id', (int) $idOrName)))
            ->first();
    }

    public function findByOrder(int $seasonId, int $order): ?Stage
    {
        return Stage::where('season_id', $seasonId)->where('stage_order', $order)->first();
    }

    public function countInSeason(int $seasonId): int
    {
        return Stage::where('season_id', $seasonId)->count();
    }

    /** Единственная стадия сезона; null — стадий нет или их несколько. */
    public function soleInSeason(int $seasonId): ?Stage
    {
        $stages = Stage::where('season_id', $seasonId)->limit(2)->get();

        return $stages->count() === 1 ? $stages->first() : null;
    }

    /** @return array{0: Stage, 1: UpsertOutcome} */
    public function upsert(?Stage $existing, int $seasonId, string $name, StageType $type, int $order, bool $hasStandings): array
    {
        return $this->persist(new Stage, $existing, [
            'season_id' => $seasonId,
            'name' => $name,
            'type' => $type,
            'stage_order' => $order,
            'has_standings' => $hasStandings,
        ]);
    }
}
