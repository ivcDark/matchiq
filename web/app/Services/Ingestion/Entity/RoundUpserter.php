<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\RoundCode;
use App\Enums\RoundType;
use App\Repositories\ExternalIdRepository;
use App\Repositories\RoundRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class RoundUpserter implements EntityUpserter
{
    public function __construct(
        private readonly RoundRepository $rounds,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Round;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $number = $entity->data['number'] ?? null;
        $type = RoundType::tryFrom((string) ($entity->data['type'] ?? ''));
        $name = trim((string) ($entity->data['name'] ?? ''));
        if (! is_int($number) || $number < 1 || $type === null) {
            throw IngestionEntityException::invalid('у раунда нужны number >= 1 и type', ['data' => $entity->data]);
        }

        $stageId = $this->externalIds->requireEntityId(
            $context->providerId,
            ExternalEntityType::Stage,
            (string) ($entity->data['stage_external_id'] ?? ''),
        );

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->rounds->find($existingId) : $this->rounds->findByNumber($stageId, $number);
        if ($existingId === null && $existing !== null && $this->externalIds->isLinked($context->providerId, $this->type(), $existing->id)) {
            throw IngestionEntityException::conflict("номер раунда {$number} стадии уже занят другим раундом", ['number' => $number]);
        }

        [$round, $outcome] = $this->rounds->upsertFromSource(
            $existing,
            $stageId,
            $number,
            $type,
            $name !== '' ? $name : null,
            RoundCode::tryFrom((string) ($entity->data['code'] ?? '')),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $round->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $round->id);
    }
}
