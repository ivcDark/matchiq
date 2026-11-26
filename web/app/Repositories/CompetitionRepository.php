<?php

namespace App\Repositories;

use App\Enums\CompetitionScope;
use App\Enums\CompetitionType;
use App\Enums\UpsertOutcome;
use App\Models\Competition;
use App\Repositories\Concerns\GeneratesUniqueSlugs;
use App\Repositories\Concerns\UpsertsModels;

class CompetitionRepository
{
    use GeneratesUniqueSlugs, UpsertsModels;

    public function find(int $id): ?Competition
    {
        return Competition::find($id);
    }

    public function findByIdOrSlug(string $idOrSlug): ?Competition
    {
        return Competition::where('slug', $idOrSlug)
            ->when(ctype_digit($idOrSlug), fn ($q) => $q->orWhere('id', (int) $idOrSlug))
            ->first();
    }

    /**
     * Тип, охват и slug задаются только при создании: их правит админ, источник не перезаписывает.
     *
     * @return array{0: Competition, 1: UpsertOutcome}
     */
    public function upsert(
        ?Competition $existing,
        ?int $countryId,
        string $name,
        CompetitionType $type,
        CompetitionScope $scope,
        ?string $logoUrl,
        string $countryCode = '',
    ): array {
        $onCreate = [];
        if ($existing === null) {
            $onCreate = [
                'slug' => $this->uniqueSlug(Competition::query(), $name, [$countryCode]),
                'type' => $type,
                'scope' => $scope,
            ];
        }

        return $this->persist(new Competition, $existing, [
            'country_id' => $countryId,
            'name' => $name,
            'logo_url' => $logoUrl,
        ], $onCreate);
    }
}
