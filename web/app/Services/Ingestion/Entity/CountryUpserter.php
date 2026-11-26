<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\CountryKind;
use App\Enums\ExternalEntityType;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class CountryUpserter implements EntityUpserter
{
    public function __construct(
        private readonly CountryRepository $countries,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Country;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $code = trim((string) ($entity->data['code'] ?? ''));
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw IngestionEntityException::invalid('у страны нет code или name');
        }

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->countries->find($existingId) : null;

        if ($existing === null) {
            $existing = $this->countries->findByCode($code);
            if ($existing !== null && $this->externalIds->isLinked($context->providerId, $this->type(), $existing->id)) {
                throw IngestionEntityException::conflict("страна с кодом {$code} уже привязана к другому external_id", ['code' => $code]);
            }
        }

        [$country, $outcome] = $this->countries->upsert(
            $existing,
            $code,
            $name,
            CountryKind::tryFrom((string) ($entity->data['kind'] ?? '')) ?? CountryKind::Country,
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $country->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $country->id);
    }
}
