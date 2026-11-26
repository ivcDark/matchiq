<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Repositories\CompetitionRepository;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class TeamUpserter implements EntityUpserter
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly CountryRepository $countries,
        private readonly SeasonRepository $seasons,
        private readonly CompetitionRepository $competitions,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Team;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у команды нет name');
        }

        $country = $this->resolveCountry($entity, $context);

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->teams->find($existingId) : null;

        [$team, $outcome] = $this->teams->upsert(
            $existing,
            $country->id,
            $name,
            ($entity->data['short_name'] ?? '') ?: null,
            ($entity->data['logo_url'] ?? '') ?: null,
            strtolower($country->code),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $team->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $team->id);
    }

    /** Страна команды: по external_id источника, иначе страна соревнования сезона (teams.country_id NOT NULL). */
    private function resolveCountry(ResultEntity $entity, UpsertContext $context): \App\Models\Country
    {
        $countryExternalId = (string) ($entity->data['country_external_id'] ?? '');
        if ($countryExternalId !== '') {
            $id = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Country, $countryExternalId);
            if ($id !== null && ($country = $this->countries->find($id)) !== null) {
                return $country;
            }
        }

        $seasonExternalId = (string) ($entity->data['season_external_id'] ?? '');
        if ($seasonExternalId !== '') {
            $seasonId = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Season, $seasonExternalId);
            $season = $seasonId ? $this->seasons->find($seasonId) : null;
            $countryId = $season ? $this->competitions->find($season->competition_id)?->country_id : null;
            if ($countryId !== null && ($country = $this->countries->find($countryId)) !== null) {
                return $country;
            }
        }

        throw IngestionEntityException::missingReference('country', $countryExternalId !== '' ? $countryExternalId : 'team.country');
    }
}
