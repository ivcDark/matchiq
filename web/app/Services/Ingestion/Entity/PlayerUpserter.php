<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\PlayerPosition;
use App\Enums\SourceProviderCode;
use App\Models\Country;
use App\Models\Player;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use App\Support\PersonName;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Игрок. Из /Players/list (`people_source = sstats_api`): ключ — числовой id игрока SStats; существующий находится по нему,
 * по flash-id (`flash_id`, сопоставлен коллектором с /Ls/Players) или по имени среди игроков команды. Профиль (имя, дата
 * рождения, рост, фото) берётся из /Players/list, позиция — из /Ls/Players, а если её нет — остаётся прежней.
 */
class PlayerUpserter implements EntityUpserter
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly CountryRepository $countries,
        private readonly TeamRepository $teams,
        private readonly ExternalIdRepository $externalIds,
        private readonly SstatsApiIds $apiIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Player;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $data = $entity->data;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у игрока нет name');
        }
        $peopleSource = $data['people_source'] ?? null;
        if ($peopleSource !== null && $peopleSource !== SourceProviderCode::SstatsApi->value) {
            throw IngestionEntityException::invalid('неизвестный people_source', ['people_source' => $peopleSource]);
        }
        if ($peopleSource !== null) {
            return $this->upsertFromApi($entity, $context, $name);
        }

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->players->find($existingId) : null;

        $position = PlayerPosition::tryFrom((string) ($data['position'] ?? ''));
        $birthDate = $this->birthDate($data['birth_date'] ?? null);
        $photoUrl = ($data['photo_url'] ?? '') ?: null;
        $hasProfile = (bool) ($data['has_profile'] ?? false);

        if ($existing !== null && ! $hasProfile) {
            // Без профиля источник знает только имя из состава: уже известные поля не затираем.
            $country = $this->countries->find($existing->country_id) ?? $this->resolveCountry($data, $context);
            $name = $existing->name;
            $position ??= $existing->position;
            $birthDate ??= $existing->birth_date;
            $photoUrl ??= $existing->photo_url;
        } else {
            $country = $this->resolveCountry($data, $context);
            $position ??= $existing?->position;
        }

        [$player, $outcome] = $this->players->upsert(
            $existing,
            $country->id,
            $name,
            $birthDate,
            $position,
            $photoUrl,
            array_filter([$birthDate?->format('Y'), strtolower($country->code)]),
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $player->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $player->id);
    }

    private function upsertFromApi(ResultEntity $entity, UpsertContext $context, string $name): UpsertResult
    {
        $data = $entity->data;
        $flashId = trim((string) ($data['flash_id'] ?? ''));
        $teamId = $this->apiIds->find(ExternalEntityType::Team, (string) ($data['team_external_id'] ?? ''));

        $existing = $this->findFromApi($entity, $context, $flashId, $name, $teamId);

        $country = $this->nationality($data)
            ?? ($existing !== null ? $this->countries->find($existing->country_id) : null)
            ?? ($teamId !== null ? $this->countries->find((int) $this->teams->find($teamId)?->country_id) : null)
            ?? throw IngestionEntityException::missingReference('country', 'player.nationality');

        $birthDate = $this->birthDate($data['birth_date'] ?? null) ?? $existing?->birth_date;
        $height = $data['height_cm'] ?? null;

        [$player, $outcome] = $this->players->upsert(
            $existing,
            $country->id,
            $name,
            $birthDate,
            PlayerPosition::tryFrom((string) ($data['position'] ?? '')) ?? $existing?->position,
            (($data['photo_url'] ?? '') ?: null) ?? $existing?->photo_url,
            array_filter([$birthDate?->format('Y'), strtolower($country->code)]),
            [
                'first_name' => trim((string) ($data['first_name'] ?? '')) ?: $existing?->first_name,
                'last_name' => trim((string) ($data['last_name'] ?? '')) ?: $existing?->last_name,
                'height_cm' => is_int($height) && $height > 0 ? $height : $existing?->height_cm,
            ],
        );

        $this->apiIds->track($this->type(), $entity->externalId, $player->id, $context->runId);
        $refs = $this->apiIds->linkRefs($context->providerId, $this->type(), $player->id, $flashId, null, $context->runId);

        return new UpsertResult($outcome, $player->id, $refs === [] ? [] : ['external_ref' => $refs]);
    }

    /** Числовой id → flash-id → единственное совпадение имени среди игроков команды, ещё без числового id. */
    private function findFromApi(ResultEntity $entity, UpsertContext $context, string $flashId, string $name, ?int $teamId): ?Player
    {
        $id = $this->apiIds->find($this->type(), $entity->externalId)
            ?? ($flashId !== '' ? $this->externalIds->findEntityId($context->providerId, $this->type(), $flashId) : null);

        if ($id === null && $teamId !== null) {
            $candidates = $this->players->namesForTeam($teamId);
            $linked = $this->externalIds->externalIdsFor($this->apiIds->providerId(), $this->type(), array_keys($candidates));
            $id = PersonName::findUnique($name, array_diff_key($candidates, $linked));
        }

        return $id !== null ? $this->players->find($id) : null;
    }

    private function nationality(array $data): ?Country
    {
        $name = trim((string) ($data['nationality'] ?? ''));

        return $name === '' ? null : $this->countries->findByName($name);
    }

    /** Страна профиля, иначе страна команды матча (players.country_id NOT NULL). */
    private function resolveCountry(array $data, UpsertContext $context): Country
    {
        $countryExternalId = (string) ($data['country_external_id'] ?? '');
        if ($countryExternalId !== '') {
            $id = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Country, $countryExternalId);
            if ($id !== null && ($country = $this->countries->find($id)) !== null) {
                return $country;
            }
        }

        $teamExternalId = (string) ($data['team_external_id'] ?? '');
        if ($teamExternalId !== '') {
            $teamId = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Team, $teamExternalId);
            $team = $teamId ? $this->teams->find($teamId) : null;
            if ($team !== null && ($country = $this->countries->find($team->country_id)) !== null) {
                return $country;
            }
        }

        throw IngestionEntityException::missingReference('country', $countryExternalId !== '' ? $countryExternalId : 'player.country');
    }

    private function birthDate(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $raw && $date->year >= 1900 && $date->lte(now()) ? $date : null;
    }
}
