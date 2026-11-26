<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\CompetitionScope;
use App\Enums\CompetitionType;
use App\Enums\ExternalEntityType;
use App\Repositories\CompetitionRepository;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class CompetitionUpserter implements EntityUpserter
{
    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly CountryRepository $countries,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Competition;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у соревнования нет name');
        }

        $country = null;
        $countryExternalId = $entity->data['country_external_id'] ?? null;
        if ($countryExternalId !== null && $countryExternalId !== '') {
            $countryId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Country, (string) $countryExternalId);
            $country = $this->countries->find($countryId);
        }

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->competitions->find($existingId) : null;

        [$competition, $outcome] = $this->competitions->upsert(
            $existing,
            $country?->id,
            $name,
            CompetitionType::tryFrom((string) ($entity->data['type'] ?? '')) ?? CompetitionType::League,
            CompetitionScope::tryFrom((string) ($entity->data['scope'] ?? '')) ?? CompetitionScope::Domestic,
            ($entity->data['logo_url'] ?? '') ?: null,
            strtolower($country?->code ?? ''),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $competition->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $competition->id);
    }
}
