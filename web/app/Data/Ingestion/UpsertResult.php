<?php

namespace App\Data\Ingestion;

use App\Enums\UpsertOutcome;

final readonly class UpsertResult
{
    public function __construct(
        public UpsertOutcome $outcome,
        public int $entityId,
    ) {}
}
