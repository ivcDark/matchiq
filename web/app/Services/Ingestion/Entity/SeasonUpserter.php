<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class SeasonUpserter implements EntityUpserter
{
    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Season;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $label = trim((string) ($entity->data['label'] ?? ''));
        if ($label === '') {
            throw IngestionEntityException::invalid('у сезона нет label');
        }

        $competitionId = $this->externalIds->requireEntityId(
            $context->providerId,
            ExternalEntityType::Competition,
            (string) ($entity->data['competition_external_id'] ?? ''),
        );

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->seasons->find($existingId) : null;

        if ($existing === null) {
            $existing = $this->seasons->findBySlug($competitionId, $this->seasons->slugFor($label));
            if ($existing !== null && $this->externalIds->isLinked($context->providerId, $this->type(), $existing->id)) {
                throw IngestionEntityException::conflict("сезон {$label} уже привязан к другому external_id", ['label' => $label]);
            }
        }

        [$season, $outcome] = $this->seasons->upsert($existing, $competitionId, $label, (bool) ($entity->data['is_current'] ?? false));

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $season->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $season->id);
    }
}
