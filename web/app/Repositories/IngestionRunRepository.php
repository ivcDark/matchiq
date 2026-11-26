<?php

namespace App\Repositories;

use App\Domains\Ingestion\RunStats;
use App\Enums\IngestionKind;
use App\Enums\IngestionPhase;
use App\Enums\IngestionStatus;
use App\Models\IngestionRun;
use App\Models\IngestionRunError;

class IngestionRunRepository
{
    public function create(int $providerId, ?int $competitionId, IngestionKind $kind, RunStats $stats): IngestionRun
    {
        return IngestionRun::create([
            'source_provider_id' => $providerId,
            'competition_id' => $competitionId,
            'kind' => $kind,
            'status' => IngestionStatus::Running,
            'started_at' => now(),
            'stats' => $stats->toArray(),
        ]);
    }

    public function find(int $id): ?IngestionRun
    {
        return IngestionRun::find($id);
    }

    public function findForUpdate(int $id): ?IngestionRun
    {
        return IngestionRun::whereKey($id)->lockForUpdate()->first();
    }

    public function findRunning(int $providerId, ?int $competitionId, IngestionKind $kind): ?IngestionRun
    {
        return IngestionRun::where('source_provider_id', $providerId)
            ->where('competition_id', $competitionId)
            ->where('kind', $kind->value)
            ->where('status', IngestionStatus::Running->value)
            ->latest('started_at')
            ->first();
    }

    public function saveStats(IngestionRun $run, RunStats $stats): void
    {
        $run->stats = $stats->toArray();
        $run->save();
    }

    public function finish(IngestionRun $run, IngestionStatus $status, RunStats $stats, ?string $error = null): void
    {
        $run->status = $status;
        $run->stats = $stats->toArray();
        $run->error = $error;
        $run->finished_at = now();
        $run->save();
    }

    public function addError(
        IngestionRun $run,
        ?string $entityType,
        ?string $externalId,
        IngestionPhase $phase,
        string $message,
        array $context = [],
        ?int $entityId = null,
    ): IngestionRunError {
        return IngestionRunError::create([
            'ingestion_run_id' => $run->id,
            'entity_type' => $entityType,
            'external_id' => $externalId,
            'entity_id' => $entityId,
            'phase' => $phase,
            'message' => mb_substr($message, 0, 2000),
            'context' => $context === [] ? null : $context,
        ]);
    }
}
