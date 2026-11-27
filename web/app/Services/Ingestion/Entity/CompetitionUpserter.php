<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\CompetitionScope;
use App\Enums\CompetitionType;
use App\Enums\CountryKind;
use App\Enums\ExternalEntityType;
use App\Models\Country;
use App\Repositories\CompetitionRepository;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Соревнование. Из /Leagues (`data.api_id`): ключ — числовой id SStats (`sstats_api`); существующее находится по нему или
 * по flash-id (провайдер `sstats`). Название и страна берутся из /Leagues (страна по коду, `WW` — регион «World»),
 * логотип остаётся прежним, если источник его не дал; тип, охват и slug существующего не меняются.
 */
class CompetitionUpserter implements EntityUpserter
{
    /** Код страны «весь мир» у международных турниров в /Leagues. */
    private const WORLD_CODE = 'WW';

    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly CountryRepository $countries,
        private readonly ExternalIdRepository $externalIds,
        private readonly SstatsApiIds $apiIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Competition;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у соревнования нет name');
        }
        if (trim((string) ($entity->data['api_id'] ?? '')) !== '') {
            return $this->upsertFromApi($entity, $context, $name);
        }

        $country = null;
        $countryExternalId = $entity->data['country_external_id'] ?? null;
        if ($countryExternalId !== null && $countryExternalId !== '') {
            $countryId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Country, (string) $countryExternalId);
            $country = $this->countries->find($countryId);
        }

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->competitions->find($existingId) : null;

        [$competition, $outcome] = $this->competitions->upsert(
            $existing,
            $country?->id,
            $name,
            CompetitionType::tryFrom((string) ($entity->data['type'] ?? '')) ?? CompetitionType::League,
            CompetitionScope::tryFrom((string) ($entity->data['scope'] ?? '')) ?? CompetitionScope::Domestic,
            ($entity->data['logo_url'] ?? '') ?: null,
            strtolower($country?->code ?? ''),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $competition->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $competition->id);
    }

    private function upsertFromApi(ResultEntity $entity, UpsertContext $context, string $name): UpsertResult
    {
        $data = $entity->data;
        $flashId = trim((string) ($data['flash_id'] ?? ''));

        $existingId = $this->apiIds->find($this->type(), $entity->externalId)
            ?? ($flashId !== '' ? $this->externalIds->findEntityId($context->providerId, $this->type(), $flashId) : null);
        $competition = $existingId ? $this->competitions->find($existingId) : null;

        // Имя и страна — из /Leagues (континентальные турниры там «World»); логотип, которого источник не дал, остаётся.
        $country = $this->apiCountry($data);
        [$competition, $outcome] = $this->competitions->upsert(
            $competition,
            $country?->id ?? $competition?->country_id,
            $name,
            CompetitionType::tryFrom((string) ($data['type'] ?? '')) ?? CompetitionType::League,
            CompetitionScope::tryFrom((string) ($data['scope'] ?? '')) ?? CompetitionScope::Domestic,
            (($data['logo_url'] ?? '') ?: null) ?? $competition?->logo_url,
            strtolower($country?->code ?? ''),
        );

        $this->apiIds->track($this->type(), $entity->externalId, $competition->id, $context->runId);
        $refs = $this->apiIds->linkRefs($context->providerId, $this->type(), $competition->id, $flashId, $data['refs'] ?? null, $context->runId);

        return new UpsertResult($outcome, $competition->id, $refs === [] ? [] : ['external_ref' => $refs]);
    }

    /** Страна по коду из /Leagues; неизвестная создаётся (`WW` — регион). */
    private function apiCountry(array $data): ?Country
    {
        $code = strtoupper(trim((string) ($data['country_code'] ?? '')));
        if ($code === '') {
            return null;
        }

        $country = $this->countries->findByCode($code);
        if ($country === null) {
            $name = trim((string) ($data['country_name'] ?? '')) ?: $code;
            [$country] = $this->countries->upsert(null, $code, $name, $code === self::WORLD_CODE ? CountryKind::Region : CountryKind::Country);
        }

        return $country;
    }
}
