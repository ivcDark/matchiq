<?php

namespace App\Data\Ingestion;

final readonly class UpsertContext
{
    public function __construct(
        public int $providerId,
        public int $runId,
    ) {}
}
