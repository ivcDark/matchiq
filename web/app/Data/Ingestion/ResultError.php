<?php

namespace App\Data\Ingestion;

use App\Enums\IngestionPhase;

final readonly class ResultError
{
    public function __construct(
        public ?string $entityType,
        public ?string $externalId,
        public IngestionPhase $phase,
        public string $message,
        public array $context = [],
    ) {}
}
