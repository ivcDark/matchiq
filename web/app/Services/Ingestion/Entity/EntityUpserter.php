<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/** Применяет одну каноническую сущность результата (resolve → upsert → external_ids). */
interface EntityUpserter
{
    public function type(): ExternalEntityType;

    /** @throws IngestionEntityException */
    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult;
}
