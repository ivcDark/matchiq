<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\CountryKind;
use App\Enums\ExternalEntityType;
use App\Enums\UpsertOutcome;
use App\Models\Country;
use App\Repositories\CompetitionRepository;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Команда. Из /Games/list (`data.api_id`): ключ — числовой id команды SStats (`sstats_api`); существующая находится по нему
 * или по flash-id (команды Ls-структуры) и не меняется, только получает id. Без совпадения по id создаётся новая — по имени
 * команды не сопоставляются.
 */
class TeamUpserter implements EntityUpserter
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly CountryRepository $countries,
        private readonly SeasonRepository $seasons,
        private readonly CompetitionRepository $competitions,
        private readonly ExternalIdRepository $externalIds,
        private readonly SstatsApiIds $apiIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Team;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $name = trim((string) ($entity->data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у команды нет name');
        }
        if (trim((string) ($entity->data['api_id'] ?? '')) !== '') {
            return $this->upsertFromApi($entity, $context, $name);
        }

        $country = $this->resolveCountry($entity, $context);

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->teams->find($existingId) : null;

        [$team, $outcome] = $this->teams->upsert(
            $existing,
            $country->id,
            $name,
            ($entity->data['short_name'] ?? '') ?: null,
            ($entity->data['logo_url'] ?? '') ?: null,
            strtolower($country->code),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $team->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $team->id);
    }

    private function upsertFromApi(ResultEntity $entity, UpsertContext $context, string $name): UpsertResult
    {
        $data = $entity->data;
        $flashId = trim((string) ($data['flash_id'] ?? ''));

        $existingId = $this->apiIds->find($this->type(), $entity->externalId)
            ?? ($flashId !== '' ? $this->externalIds->findEntityId($context->providerId, $this->type(), $flashId) : null);
        $team = $existingId ? $this->teams->find($existingId) : null;

        $outcome = UpsertOutcome::Unchanged;
        if ($team === null) {
            $country = $this->apiCountry($data);
            [$team, $outcome] = $this->teams->upsert(null, $country->id, $name, null, ($data['logo_url'] ?? '') ?: null, strtolower($country->code));
        }

        $this->apiIds->track($this->type(), $entity->externalId, $team->id, $context->runId);
        $refs = $this->apiIds->linkRefs($context->providerId, $this->type(), $team->id, $flashId, $data['refs'] ?? null, $context->runId);

        return new UpsertResult($outcome, $team->id, $refs === [] ? [] : ['external_ref' => $refs]);
    }

    /** Страна новой команды по коду из /Games/list (teams.country_id NOT NULL); неизвестный код создаёт страну. */
    private function apiCountry(array $data): Country
    {
        $code = strtoupper(trim((string) ($data['country_code'] ?? '')));
        if ($code === '') {
            throw IngestionEntityException::missingReference('country', 'team.country');
        }

        $country = $this->countries->findByCode($code);
        if ($country === null) {
            [$country] = $this->countries->upsert(null, $code, trim((string) ($data['country_name'] ?? '')) ?: $code, CountryKind::Country);
        }

        return $country;
    }

    /** Страна команды: по external_id источника, иначе страна соревнования сезона (teams.country_id NOT NULL). */
    private function resolveCountry(ResultEntity $entity, UpsertContext $context): Country
    {
        $countryExternalId = (string) ($entity->data['country_external_id'] ?? '');
        if ($countryExternalId !== '') {
            $id = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Country, $countryExternalId);
            if ($id !== null && ($country = $this->countries->find($id)) !== null) {
                return $country;
            }
        }

        $seasonExternalId = (string) ($entity->data['season_external_id'] ?? '');
        if ($seasonExternalId !== '') {
            $seasonId = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Season, $seasonExternalId);
            $season = $seasonId ? $this->seasons->find($seasonId) : null;
            $countryId = $season ? $this->competitions->find($season->competition_id)?->country_id : null;
            if ($countryId !== null && ($country = $this->countries->find($countryId)) !== null) {
                return $country;
            }
        }

        throw IngestionEntityException::missingReference('country', $countryExternalId !== '' ? $countryExternalId : 'team.country');
    }
}
