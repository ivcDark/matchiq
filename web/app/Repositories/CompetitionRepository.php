<?php

namespace App\Repositories;

use App\Enums\CompetitionScope;
use App\Enums\CompetitionType;
use App\Enums\ExternalEntityType;
use App\Enums\UpsertOutcome;
use App\Models\Competition;
use App\Models\ExternalId;
use App\Repositories\Concerns\GeneratesUniqueSlugs;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     * Соревнования для регулярного ingest: явный список slug'ов (override из env) либо все с `ingest_enabled`
     * и внешним id одного из провайдеров. Витринный `status` не учитывается.
     *
     * @param  list<int>  $providerIds
     * @param  list<string>  $slugs
     * @return Collection<int, Competition>
     */
    public function forScheduledIngest(array $providerIds, array $slugs = []): Collection
    {
        return Competition::query()
            ->when(
                $slugs !== [],
                fn ($q) => $q->whereIn('slug', $slugs),
                fn ($q) => $q->where('ingest_enabled', true)->whereIn('id', $this->externalCompetitionIds($providerIds)),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Id лиг для соревнований с `ingest_enabled` (фильтр `league_ids` в sync_catalog): по одному на соревнование,
     * от первого провайдера списка, у которого он есть (числовой id SStats, иначе flash-id).
     *
     * @param  list<int>  $providerIds  в порядке предпочтения
     * @return list<string>
     */
    public function enabledExternalIds(array $providerIds): array
    {
        $rank = array_flip($providerIds);

        return ExternalId::whereIn('source_provider_id', $providerIds)
            ->where('entity_type', ExternalEntityType::Competition->value)
            ->whereIn('entity_id', Competition::where('ingest_enabled', true)->select('id'))
            ->get(['entity_id', 'source_provider_id', 'external_id'])
            ->sortBy(fn (ExternalId $e) => [$e->entity_id, $rank[$e->source_provider_id]])
            ->unique('entity_id')
            ->map(fn (ExternalId $e) => (string) $e->external_id)
            ->values()
            ->all();
    }

    public function setIngestEnabled(Competition $competition, bool $enabled): Competition
    {
        $competition->forceFill(['ingest_enabled' => $enabled])->save();

        return $competition;
    }

    /**
     * @param  list<int>  $providerIds
     * @return Builder<ExternalId>
     */
    private function externalCompetitionIds(array $providerIds)
    {
        return ExternalId::whereIn('source_provider_id', $providerIds)
            ->where('entity_type', ExternalEntityType::Competition->value)
            ->select('entity_id');
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
