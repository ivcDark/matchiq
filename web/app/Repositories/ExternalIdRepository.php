<?php

namespace App\Repositories;

use App\Enums\ExternalEntityType;
use App\Enums\MatchedBy;
use App\Enums\UpsertOutcome;
use App\Models\ExternalId;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

class ExternalIdRepository
{
    public function findEntityId(int $providerId, ExternalEntityType $type, string $externalId): ?int
    {
        $id = ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->where('external_id', $externalId)
            ->value('entity_id');

        return $id === null ? null : (int) $id;
    }

    /** @throws IngestionEntityException */
    public function requireEntityId(int $providerId, ExternalEntityType $type, ?string $externalId): int
    {
        if ($externalId === null || $externalId === '') {
            throw IngestionEntityException::invalid("не указан внешний id для ссылки на {$type->value}");
        }

        return $this->findEntityId($providerId, $type, $externalId)
            ?? throw IngestionEntityException::missingReference($type->value, $externalId);
    }

    public function externalIdFor(int $providerId, ExternalEntityType $type, int $entityId): ?string
    {
        return ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->where('entity_id', $entityId)
            ->value('external_id');
    }

    /**
     * @param  list<int>  $entityIds
     * @return array<int, string> entity_id → external_id
     */
    public function externalIdsFor(int $providerId, ExternalEntityType $type, array $entityIds): array
    {
        return ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->whereIn('entity_id', $entityIds)
            ->pluck('external_id', 'entity_id')
            ->all();
    }

    /**
     * @param  list<string>  $externalIds
     * @return array<string, int> external_id → entity_id (только найденные)
     */
    public function entityIdsFor(int $providerId, ExternalEntityType $type, array $externalIds): array
    {
        return ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->whereIn('external_id', $externalIds)
            ->pluck('entity_id', 'external_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isLinked(int $providerId, ExternalEntityType $type, int $entityId): bool
    {
        return ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->where('entity_id', $entityId)
            ->exists();
    }

    /** Создаёт/обновляет маппинг и всегда отмечает «сущность жива в источнике» в этом прогоне. */
    public function track(
        int $providerId,
        ExternalEntityType $type,
        string $externalId,
        int $entityId,
        int $runId,
        ?string $externalUrl = null,
        MatchedBy $matchedBy = MatchedBy::Auto,
    ): ExternalId {
        $row = ExternalId::firstOrNew([
            'source_provider_id' => $providerId,
            'entity_type' => $type->value,
            'external_id' => $externalId,
        ]);

        if (! $row->exists) {
            $row->matched_by = $matchedBy;
        }

        $row->entity_id = $entityId;
        $row->last_seen_at = now();
        $row->last_ingestion_run_id = $runId;
        if ($externalUrl !== null) {
            $row->external_url = $externalUrl;
        }
        $row->save();

        return $row;
    }

    /**
     * Привязывает дополнительный идентификатор к сущности: у сущности не больше одного id на провайдера.
     * Тот же id → unchanged (строка не пишется); другой id у сущности → заменяется; id занят другой сущностью → конфликт.
     *
     * @throws IngestionEntityException
     */
    public function link(int $providerId, ExternalEntityType $type, string $externalId, int $entityId, int $runId): UpsertOutcome
    {
        $owner = $this->findEntityId($providerId, $type, $externalId);
        if ($owner !== null && $owner !== $entityId) {
            throw IngestionEntityException::conflict('внешний id уже принадлежит другой сущности', [
                'entity_type' => $type->value,
                'external_id' => $externalId,
            ]);
        }
        if ($owner !== null) {
            return UpsertOutcome::Unchanged;
        }

        $current = ExternalId::where('source_provider_id', $providerId)
            ->where('entity_type', $type->value)
            ->where('entity_id', $entityId)
            ->first();

        $row = $current ?? new ExternalId([
            'source_provider_id' => $providerId,
            'entity_type' => $type->value,
            'entity_id' => $entityId,
            'matched_by' => MatchedBy::Auto,
        ]);
        $row->external_id = $externalId;
        $row->last_seen_at = now();
        $row->last_ingestion_run_id = $runId;
        $row->save();

        return $current === null ? UpsertOutcome::Created : UpsertOutcome::Updated;
    }
}
