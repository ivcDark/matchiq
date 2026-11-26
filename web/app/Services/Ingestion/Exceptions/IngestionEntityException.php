<?php

namespace App\Services\Ingestion\Exceptions;

use App\Enums\IngestionPhase;
use RuntimeException;

/** Сбой одной сущности: пишется в ingestion_run_errors, прогон продолжается. */
class IngestionEntityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly IngestionPhase $phase = IngestionPhase::Upsert,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function missingReference(string $what, string $externalId): self
    {
        return new self(
            "не найдена сущность {$what} (external_id={$externalId}), загрузите её раньше",
            IngestionPhase::Match,
            ['reference' => $what, 'external_id' => $externalId],
        );
    }

    public static function invalid(string $message, array $context = []): self
    {
        return new self($message, IngestionPhase::Normalize, $context);
    }

    public static function conflict(string $message, array $context = []): self
    {
        return new self($message, IngestionPhase::Match, $context);
    }
}
