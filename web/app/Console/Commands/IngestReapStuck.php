<?php

namespace App\Console\Commands;

use App\Domains\Ingestion\RunStats;
use App\Enums\IngestionStatus;
use App\Repositories\IngestionRunRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestReapStuck extends Command
{
    protected $signature = 'ingest:reap-stuck
        {--minutes= : порог в минутах (по умолчанию INGEST_STUCK_AFTER_MINUTES)}
        {--dry-run : только показать зависшие прогоны}';

    protected $description = 'Закрыть зависшие прогоны ingest (running дольше порога) со статусом failed и error = timeout';

    public function handle(IngestionRunRepository $runs): int
    {
        $raw = (string) $this->option('minutes');
        if ($raw !== '' && (! ctype_digit($raw) || (int) $raw < 1)) {
            $this->error('--minutes: ожидается целое число минут >= 1.');

            return self::FAILURE;
        }

        $minutes = $raw !== '' ? (int) $raw : max(1, (int) config('rabbitmq.ingest.stuck_after_minutes'));
        $stuck = $runs->findRunningStartedBefore(now()->subMinutes($minutes));
        $dry = (bool) $this->option('dry-run');
        $closed = 0;

        foreach ($stuck as $candidate) {
            if ($dry) {
                $this->line("run #{$candidate->id} {$candidate->kind->value}: зависший с {$candidate->started_at->toDateTimeString()}");

                continue;
            }

            $reaped = DB::transaction(function () use ($runs, $candidate): bool {
                $run = $runs->findForUpdate($candidate->id);
                if ($run === null || $run->status !== IngestionStatus::Running) {
                    return false;
                }

                $runs->finish($run, IngestionStatus::Failed, RunStats::fromArray($run->stats), 'timeout');

                return true;
            });

            if ($reaped) {
                $closed++;
                $this->line("run #{$candidate->id} {$candidate->kind->value}: закрыт как failed (timeout)");
            }
        }

        $this->info($dry
            ? "Зависших прогонов (> {$minutes} мин): {$stuck->count()}"
            : "Закрыто зависших прогонов (> {$minutes} мин): {$closed}");

        return self::SUCCESS;
    }
}
