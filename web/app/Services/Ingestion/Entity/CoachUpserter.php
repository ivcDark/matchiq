<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Repositories\CoachRepository;
use App\Repositories\CountryRepository;
use App\Repositories\ExternalIdRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use Illuminate\Support\Carbon;
use Throwable;

class CoachUpserter implements EntityUpserter
{
    public function __construct(
        private readonly CoachRepository $coaches,
        private readonly CountryRepository $countries,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Coach;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $data = $entity->data;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw IngestionEntityException::invalid('у тренера нет name');
        }

        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        $existing = $existingId ? $this->coaches->find($existingId) : null;

        $countryId = null;
        $countryExternalId = (string) ($data['country_external_id'] ?? '');
        if ($countryExternalId !== '') {
            $id = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Country, $countryExternalId);
            $countryId = $id !== null ? $this->countries->find($id)?->id : null;
        }
        $birthDate = $this->birthDate($data['birth_date'] ?? null);

        if ($existing !== null && ! (bool) ($data['has_profile'] ?? false)) {
            $name = $existing->name;
            $countryId ??= $existing->country_id;
            $birthDate ??= $existing->birth_date;
        }

        [$coach, $outcome] = $this->coaches->upsert($existing, $countryId, $name, $birthDate);

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $coach->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $coach->id);
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
