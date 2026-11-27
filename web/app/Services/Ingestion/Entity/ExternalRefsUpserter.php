<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\SourceProviderCode;
use App\Enums\UpsertOutcome;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Дополнительные идентификаторы сущности (числовой id SStats API, flashscore, pari): одна строка `external_ids` на провайдера.
 * Сама сущность уже есть и найдена по ключу ingest (провайдер `sstats`), здесь она не создаётся и не меняется.
 */
class ExternalRefsUpserter implements EntityUpserter
{
    /** Типы сущностей, у которых SStats отдаёт mappings. */
    private const TYPES = [ExternalEntityType::Match, ExternalEntityType::Team, ExternalEntityType::Competition];

    public function __construct(
        private readonly ExternalIdRepository $externalIds,
        private readonly SourceProviderRepository $providers,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::ExternalRefs;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $target = ExternalEntityType::tryFrom((string) ($entity->data['entity_type'] ?? ''));
        if ($target === null || ! in_array($target, self::TYPES, true)) {
            throw IngestionEntityException::invalid('у external_refs неизвестный entity_type', ['entity_type' => $entity->data['entity_type'] ?? null]);
        }

        $refs = $entity->data['refs'] ?? null;
        if (! is_array($refs) || $refs === []) {
            throw IngestionEntityException::invalid('у external_refs нет refs');
        }

        $entityId = $this->externalIds->requireEntityId($context->providerId, $target, $entity->externalId);

        $counts = [];
        foreach ($refs as $code => $externalId) {
            $provider = SourceProviderCode::tryFrom((string) $code);
            if ($provider === null || ! $provider->isReference()) {
                throw IngestionEntityException::invalid('у external_refs неизвестный провайдер идентификатора', ['provider' => $code]);
            }
            if (! is_string($externalId) || trim($externalId) === '') {
                throw IngestionEntityException::invalid('у external_refs пустой идентификатор', ['provider' => $code]);
            }

            $outcome = $this->externalIds->link($this->providers->ensure($provider)->id, $target, trim($externalId), $entityId, $context->runId);
            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        return new UpsertResult($this->aggregate($counts), $entityId, ['external_ref' => $counts]);
    }

    /** @param  array<string, int>  $counts */
    private function aggregate(array $counts): UpsertOutcome
    {
        if (($counts['created'] ?? 0) > 0 && ($counts['updated'] ?? 0) === 0 && ($counts['unchanged'] ?? 0) === 0) {
            return UpsertOutcome::Created;
        }

        return ($counts['created'] ?? 0) + ($counts['updated'] ?? 0) > 0 ? UpsertOutcome::Updated : UpsertOutcome::Unchanged;
    }
}
