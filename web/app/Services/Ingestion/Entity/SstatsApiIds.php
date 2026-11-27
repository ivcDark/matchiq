<?php

namespace App\Services\Ingestion\Entity;

use App\Enums\ExternalEntityType;
use App\Enums\SourceProviderCode;
use App\Enums\UpsertOutcome;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Числовые id основного API SStats (/Leagues, /Games, /Players) — провайдер `sstats_api`, главный ключ сущностей из этих
 * эндпоинтов. Flash-id той же сущности (провайдер `sstats`, ключ Ls-эндпоинтов) и mappings (flashscore, pari) привязываются
 * дополнительно: занятый другой сущностью id не ошибка сущности, а `external_ref.skipped` в счётчиках.
 */
final class SstatsApiIds
{
    private ?int $apiProviderId = null;

    public function __construct(
        private readonly ExternalIdRepository $externalIds,
        private readonly SourceProviderRepository $providers,
    ) {}

    public function providerId(): int
    {
        return $this->apiProviderId ??= $this->providers->ensure(SourceProviderCode::SstatsApi)->id;
    }

    public function find(ExternalEntityType $type, ?string $apiId): ?int
    {
        $apiId = trim((string) $apiId);

        return $apiId === '' ? null : $this->externalIds->findEntityId($this->providerId(), $type, $apiId);
    }

    /** @throws IngestionEntityException */
    public function require(ExternalEntityType $type, ?string $apiId): int
    {
        return $this->externalIds->requireEntityId($this->providerId(), $type, $apiId === null ? null : trim($apiId));
    }

    public function track(ExternalEntityType $type, string $apiId, int $entityId, int $runId): void
    {
        $this->externalIds->track($this->providerId(), $type, $apiId, $entityId, $runId);
    }

    /**
     * Flash-id (провайдер ingest `sstats`) и mappings сущности. Уже привязанный к сущности другой flash-id не заменяется:
     * под ним сущность знают Ls-эндпоинты.
     *
     * @param  array<string, mixed>  $refs  код провайдера → id
     * @return array<string, int> outcome → количество
     */
    public function linkRefs(int $sstatsProviderId, ExternalEntityType $type, int $entityId, ?string $flashId, mixed $refs, int $runId): array
    {
        $counts = [];
        $add = function (string $outcome) use (&$counts): void {
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        };

        $flashId = trim((string) $flashId);
        if ($flashId !== '' && $this->externalIds->externalIdFor($sstatsProviderId, $type, $entityId) === null) {
            $add($this->tryLink($sstatsProviderId, $type, $flashId, $entityId, $runId));
        }

        foreach (is_array($refs) ? $refs : [] as $code => $externalId) {
            $provider = SourceProviderCode::tryFrom((string) $code);
            if ($provider === null || ! $provider->isReference() || $provider === SourceProviderCode::SstatsApi) {
                continue;
            }
            if (! is_string($externalId) || trim($externalId) === '') {
                continue;
            }
            $add($this->tryLink($this->providers->ensure($provider)->id, $type, trim($externalId), $entityId, $runId));
        }

        return $counts;
    }

    private function tryLink(int $providerId, ExternalEntityType $type, string $externalId, int $entityId, int $runId): string
    {
        try {
            return $this->externalIds->link($providerId, $type, $externalId, $entityId, $runId)->value;
        } catch (IngestionEntityException) {
            return UpsertOutcome::Skipped->value;
        }
    }
}
