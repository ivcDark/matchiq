<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\PlayerPosition;
use App\Models\Country;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use Illuminate\Support\Carbon;
use Throwable;

class PlayerUpserter implements EntityUpserter
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly CountryRepository $countries,
        private readonly TeamRepository $teams,
        private readonly ExternalIdRepository $externalIds,
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
