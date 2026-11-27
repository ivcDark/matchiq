<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Repositories\ExternalIdRepository;
use App\Repositories\StageGroupRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class StageGroupUpserter implements EntityUpserter
{
    public function __construct(
        private readonly StageGroupRepository $groups,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::StageGroup;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у группы нет name', ['data' => $entity->data]);
        }

        $stageId = $this->externalIds->requireEntityId(
            $context->providerId,
            ExternalEntityType::Stage,
            (string) ($entity->data['stage_external_id'] ?? ''),
        );

        [$group, $outcome] = $this->groups->upsert($stageId, $name, max(1, (int) ($entity->data['group_order'] ?? 1)));

        return new UpsertResult($outcome, $group->id);
    }
}
