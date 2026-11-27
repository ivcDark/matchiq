<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\ResultMessage;
use App\Data\Ingestion\UpsertContext;
use App\Domains\Ingestion\RunStats;
use App\Domains\Ingestion\RunStatusResolver;
use App\Enums\IngestionMessageKind;
use App\Enums\IngestionPhase;
use App\Enums\IngestionStatus;
use App\Models\IngestionRun;
use App\Repositories\IngestionRunRepository;
use App\Services\Ingestion\Entity\CompetitionUpserter;
use App\Services\Ingestion\Entity\CountryUpserter;
use App\Services\Ingestion\Entity\EntityUpserter;
use App\Services\Ingestion\Entity\MatchUpserter;
use App\Services\Ingestion\Entity\SeasonUpserter;
use App\Services\Ingestion\Entity\StageUpserter;
use App\Services\Ingestion\Entity\TeamUpserter;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use App\Services\Ingestion\Exceptions\InvalidResultMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Применяет сообщение результата collector: batch → upsert сущностей, done/failed → закрытие прогона.
 * Транзакция — на сущность; счётчики прогона сливаются в конце батча под lock строки прогона.
 */
class ApplyIngestionResult
{
    /** @var array<string, EntityUpserter> */
    private array $upserters = [];

    public function __construct(
        private readonly IngestionRunRepository $runs,
        CountryUpserter $countries,
        CompetitionUpserter $competitions,
        SeasonUpserter $seasons,
        StageUpserter $stages,
        TeamUpserter $teams,
        MatchUpserter $matches,
    ) {
        foreach ([$countries, $competitions, $seasons, $stages, $teams, $matches] as $upserter) {
            $this->upserters[$upserter->type()->value] = $upserter;
        }
    }

    /** @throws InvalidResultMessage */
    public function handle(ResultMessage $message): void
    {
        $run = $this->runs->find($message->runId);

        if ($run === null || $run->status !== IngestionStatus::Running) {
            Log::info('ingest: сообщение по закрытому или неизвестному прогону отброшено', [
                'run_id' => $message->runId,
                'kind' => $message->kind->value,
            ]);

            return;
        }

        match ($message->kind) {
            IngestionMessageKind::Batch => $this->applyBatch($run, $message),
            IngestionMessageKind::Done => $this->applyDone($message),
            IngestionMessageKind::Failed => $this->applyFailed($message),
        };
    }

    private function applyBatch(IngestionRun $run, ResultMessage $message): void
    {
        if ($message->seq < 1) {
            throw new InvalidResultMessage('у batch нет seq >= 1');
        }

        $stats = RunStats::fromArray($run->stats);
        if (in_array($message->seq, $stats->context('batches_seen', []), true)) {
            return;
        }

        $context = new UpsertContext($run->source_provider_id, $run->id);
        $local = RunStats::empty();
        $errors = [];

        $entities = $message->entities;
        usort($entities, fn (ResultEntity $a, ResultEntity $b) => $a->rank() <=> $b->rank());

        foreach ($entities as $entity) {
            try {
                $upserter = $this->upserters[$entity->type->value]
                    ?? throw new IngestionEntityException("тип {$entity->type->value} пока не поддерживается", IngestionPhase::Match);

                $result = DB::transaction(fn () => $upserter->upsert($entity, $context));
                $local->recordOutcome($entity->type->value, $result->outcome);
            } catch (IngestionEntityException $e) {
                $local->recordFailure($entity->type->value);
                $errors[] = [$entity->type->value, $entity->externalId, $e->phase, $e->getMessage(), $e->context];
            } catch (Throwable $e) {
                report($e);
                $local->recordFailure($entity->type->value);
                $errors[] = [$entity->type->value, $entity->externalId, IngestionPhase::Upsert, class_basename($e).': '.$e->getMessage(), []];
            }
        }

        foreach ($message->errors as $error) {
            $local->recordFailure($error->entityType);
            $errors[] = [$error->entityType, $error->externalId, $error->phase, $error->message, $error->context];
        }

        DB::transaction(function () use ($run, $message, $local, $errors): void {
            $locked = $this->runs->findForUpdate($run->id);
            if ($locked === null || $locked->status !== IngestionStatus::Running) {
                return;
            }

            $stats = RunStats::fromArray($locked->stats);
            $seen = $stats->context('batches_seen', []);
            if (in_array($message->seq, $seen, true)) {
                return;
            }

            $stats->merge($local);
            $seen[] = $message->seq;
            $stats->setContext('batches_seen', $seen);

            $logged = (int) $stats->context('errors_logged', 0);
            $limit = (int) config('rabbitmq.ingest.max_run_errors');
            foreach ($errors as [$type, $externalId, $phase, $text, $errorContext]) {
                if ($logged >= $limit) {
                    $stats->setContext('errors_truncated', true);
                    break;
                }
                $this->runs->addError($locked, $type, $externalId, $phase, $text, $errorContext);
                $logged++;
            }
            $stats->setContext('errors_logged', $logged);

            $this->saveOrFinish($locked, $stats);
        });
    }

    private function applyDone(ResultMessage $message): void
    {
        DB::transaction(function () use ($message): void {
            $run = $this->runs->findForUpdate($message->runId);
            if ($run === null || $run->status !== IngestionStatus::Running) {
                return;
            }

            $stats = RunStats::fromArray($run->stats);
            $stats->setContext('batches_total', $message->batchesTotal);
            $stats->setContext('source', $message->source);

            $this->saveOrFinish($run, $stats);
        });
    }

    private function applyFailed(ResultMessage $message): void
    {
        DB::transaction(function () use ($message): void {
            $run = $this->runs->findForUpdate($message->runId);
            if ($run === null || $run->status !== IngestionStatus::Running) {
                return;
            }

            $stats = RunStats::fromArray($run->stats);
            $stats->setContext('retryable', $message->retryable);

            $this->runs->finish($run, IngestionStatus::Failed, $stats, $message->message !== '' ? $message->message : 'collector failed');
        });
    }

    /** Закрывает прогон, когда пришёл done и принято `batches_total` батчей; иначе сохраняет счётчики. */
    private function saveOrFinish(IngestionRun $run, RunStats $stats): void
    {
        $total = $stats->context('batches_total');

        if ($total !== null && count($stats->context('batches_seen', [])) >= (int) $total) {
            $this->runs->finish($run, RunStatusResolver::resolve($stats), $stats);

            return;
        }

        $this->runs->saveStats($run, $stats);
    }
}
