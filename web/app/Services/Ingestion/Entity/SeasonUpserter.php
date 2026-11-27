<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Models\Season;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Сезон. Из /Leagues (`data.competition_api_id`): ключ — uid сезона SStats (`sstats_api`), с датами и признаком текущего.
 * Из sync_structure (fallback на Ls): ключ — id Ls-сезона, `is_current` не приходит и не меняется.
 */
class SeasonUpserter implements EntityUpserter
{
    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly ExternalIdRepository $externalIds,
        private readonly SstatsApiIds $apiIds,
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
        if (trim((string) ($entity->data['competition_api_id'] ?? '')) !== '') {
            return $this->upsertFromApi($entity, $context, $label);
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

        [$season, $outcome] = $this->seasons->upsert($existing, $competitionId, $label, $this->isCurrent($entity->data, $existing));

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $season->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $season->id);
    }

    private function upsertFromApi(ResultEntity $entity, UpsertContext $context, string $label): UpsertResult
    {
        $data = $entity->data;
        $competitionId = $this->apiIds->require(ExternalEntityType::Competition, (string) $data['competition_api_id']);

        $existing = ($id = $this->apiIds->find($this->type(), $entity->externalId)) ? $this->seasons->find($id) : null;
        if ($existing === null) {
            $existing = $this->seasons->findBySlug($competitionId, $this->seasons->slugFor($label));
            if ($existing !== null && $this->externalIds->isLinked($this->apiIds->providerId(), $this->type(), $existing->id)) {
                throw IngestionEntityException::conflict("сезон {$label} уже привязан к другому uid SStats", ['label' => $label]);
            }
        }

        [$season, $outcome] = $this->seasons->upsert(
            $existing,
            $competitionId,
            $label,
            $this->isCurrent($data, $existing),
            $this->date($data['starts_on'] ?? null),
            $this->date($data['ends_on'] ?? null),
        );

        $this->apiIds->track($this->type(), $entity->externalId, $season->id, $context->runId);

        return new UpsertResult($outcome, $season->id);
    }

    /** Нет `is_current` в данных — признак не меняется. */
    private function isCurrent(array $data, ?Season $existing): bool
    {
        return array_key_exists('is_current', $data) ? (bool) $data['is_current'] : (bool) $existing?->is_current;
    }

    private function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }
}
