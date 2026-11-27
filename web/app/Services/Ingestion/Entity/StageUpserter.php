<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\StageType;
use App\Models\Season;
use App\Models\Stage;
use App\Repositories\ExternalIdRepository;
use App\Repositories\StageRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class StageUpserter implements EntityUpserter
{
    public function __construct(
        private readonly StageRepository $stages,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Stage;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        $type = StageType::tryFrom((string) ($entity->data['type'] ?? ''));
        $order = (int) ($entity->data['stage_order'] ?? 0);
        if ($name === '' || $type === null || $order < 1) {
            throw IngestionEntityException::invalid('у стадии нужны name, type и stage_order >= 1', ['data' => $entity->data]);
        }

        $seasonId = $this->externalIds->requireEntityId(
            $context->providerId,
            ExternalEntityType::Season,
            (string) ($entity->data['season_external_id'] ?? ''),
        );

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->stages->find($existingId) : null;

        if ($existing === null) {
            $existing = $this->stages->findByOrder($seasonId, $order);
            if ($existing !== null && $this->externalIds->isLinked($context->providerId, $this->type(), $existing->id)) {
                throw IngestionEntityException::conflict("stage_order {$order} сезона уже занят другой стадией", ['stage_order' => $order]);
            }
        }

        [$stage, $outcome] = $this->stages->upsert(
            $existing,
            $seasonId,
            $name,
            $type,
            $order,
            (bool) ($entity->data['has_standings'] ?? $type->hasStandings()),
        );

        $this->applyDefaultRules($stage, $seasonId);

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $stage->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $stage->id);
    }

    private function applyDefaultRules(Stage $stage, int $seasonId): void
    {
        if (! $stage->type->hasStandings() || ($stage->rules ?? []) !== []) {
            return;
        }

        $slug = Season::with('competition:id,slug')->find($seasonId)?->competition?->slug;
        $rules = $slug === null ? null : config('matchiq.stage_rules.'.$slug);
        if (is_array($rules) && $rules !== []) {
            $stage->forceFill(['rules' => $rules])->save();
        }
    }
}
