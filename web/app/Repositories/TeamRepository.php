<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\Team;
use App\Repositories\Concerns\GeneratesUniqueSlugs;
use App\Repositories\Concerns\UpsertsModels;

class TeamRepository
{
    use GeneratesUniqueSlugs, UpsertsModels;

    public function find(int $id): ?Team
    {
        return Team::find($id);
    }

    /** @return array{0: Team, 1: UpsertOutcome} */
    public function upsert(
        ?Team $existing,
        int $countryId,
        string $name,
        ?string $shortName,
        ?string $logoUrl,
        string $countryCode = '',
    ): array {
        $onCreate = [];
        if ($existing === null) {
            $onCreate = ['slug' => $this->uniqueSlug(Team::query(), $name, [$countryCode])];
        }

        return $this->persist(new Team, $existing, [
            'country_id' => $countryId,
            'name' => $name,
            'short_name' => $shortName,
            'logo_url' => $logoUrl,
        ], $onCreate);
    }
}
