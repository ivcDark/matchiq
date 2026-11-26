<?php

namespace App\Domains\Ingestion;

use App\Enums\IngestionStatus;

final class RunStatusResolver
{
    public static function resolve(RunStats $stats): IngestionStatus
    {
        if ($stats->count('failed') === 0) {
            return IngestionStatus::Ok;
        }

        return $stats->succeeded() > 0 ? IngestionStatus::Partial : IngestionStatus::Failed;
    }
}
