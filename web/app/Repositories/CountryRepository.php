<?php

namespace App\Repositories;

use App\Enums\CountryKind;
use App\Enums\UpsertOutcome;
use App\Models\Country;
use App\Repositories\Concerns\UpsertsModels;

class CountryRepository
{
    use UpsertsModels;

    public function find(int $id): ?Country
    {
        return Country::find($id);
    }

    public function findByCode(string $code): ?Country
    {
        return Country::where('code', $code)->first();
    }

    /** @return array{0: Country, 1: UpsertOutcome} */
    public function upsert(?Country $existing, string $code, string $name, CountryKind $kind): array
    {
        return $this->persist(new Country, $existing, [
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
        ]);
    }
}
