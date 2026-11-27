<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Repositories\ExternalIdRepository;
use App\Repositories\StageGroupRepository;
use App\Repositories\StageTeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class StageTeamUpserter implements EntityUpserter
{
    public function __construct(
        private readonly StageTeamRepository $stageTeams,
        private readonly StageGroupRepository $groups,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::StageTeam;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $stageId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Stage, $entity->data['stage_external_id'] ?? null);
        $teamId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Team, $entity->data['team_external_id'] ?? null);

        $groupId = null;
        $groupName = trim((string) ($entity->data['group_name'] ?? ''));
        if ($groupName !== '') {
            $groupId = $this->groups->findByName($stageId, $groupName)?->id
                ?? throw IngestionEntityException::missingReference('stage_group', $groupName);
        }

        [$stageTeam, $outcome] = $this->stageTeams->upsert($stageId, $teamId, $groupId);

        if ($groupId !== null) {
            $this->stageTeams->assignGroupToMatches($stageId, $teamId, $groupId);
        }

        return new UpsertResult($outcome, $stageTeam->id);
    }
}
