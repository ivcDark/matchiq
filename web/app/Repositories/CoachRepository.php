<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\Coach;
use App\Repositories\Concerns\UpsertsModels;
use Carbon\CarbonInterface;

class CoachRepository
{
    use UpsertsModels;

    public function find(int $id): ?Coach
    {
        return Coach::find($id);
    }

    /** @return array{0: Coach, 1: UpsertOutcome} */
    public function upsert(?Coach $existing, ?int $countryId, string $name, ?CarbonInterface $birthDate): array
    {
        return $this->persist(new Coach, $existing, [
            'country_id' => $countryId,
            'name' => $name,
            'birth_date' => $birthDate,
        ]);
    }
}
