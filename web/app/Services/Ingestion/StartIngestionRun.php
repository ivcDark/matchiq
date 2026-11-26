<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\CommandMessage;
use App\Domains\Ingestion\RunStats;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\SourceProviderCode;
use App\Models\IngestionRun;
use App\Repositories\IngestionRunRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\Messaging\CommandPublisher;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/** Создаёт ingestion_runs (running) и публикует команду в RabbitMQ. Ни HTTP к источнику, ни парсинга. */
class StartIngestionRun
{
    public function __construct(
        private readonly SourceProviderRepository $providers,
        private readonly IngestionRunRepository $runs,
        private readonly CommandPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Тело команды (filters / competition / season_external_id).
     * @param  array<string, mixed>  $context  stats.context: trigger, requested_by и т.п.
     *
     * @throws InvalidArgumentException если такой прогон уже идёт или не удалось взять lock
     */
    public function handle(
        IngestionKind $kind,
        array $payload,
        ?int $competitionId = null,
        array $context = [],
        SourceProviderCode $provider = SourceProviderCode::Sstats,
    ): IngestionRun {
        $providerModel = $this->providers->ensure($provider);
        $lock = Cache::lock("ingest:{$provider->value}:".($competitionId ?? '-').":{$kind->value}", 30);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Такой прогон уже запускается, повторите позже.');
        }

        try {
            $this->failStale($providerModel->id, $competitionId, $kind);

            if ($this->runs->findRunning($providerModel->id, $competitionId, $kind) !== null) {
                throw new InvalidArgumentException("Прогон {$kind->value} уже выполняется.");
            }

            $run = $this->runs->create(
                $providerModel->id,
                $competitionId,
                $kind,
                RunStats::empty($context + ['trigger' => 'cli', 'requested_by' => null]),
            );

            try {
                $this->publisher->publish(new CommandMessage($run->id, $kind, $provider->value, $payload + ['context' => $context === [] ? new \stdClass : $context]));
            } catch (Throwable $e) {
                $stats = RunStats::fromArray($run->stats);
                $this->runs->finish($run, IngestionStatus::Failed, $stats, 'не удалось опубликовать команду: '.$e->getMessage());

                throw $e;
            }

            return $run;
        } finally {
            $lock->release();
        }
    }

    private function failStale(int $providerId, ?int $competitionId, IngestionKind $kind): void
    {
        $run = $this->runs->findRunning($providerId, $competitionId, $kind);
        $minutes = (int) config('rabbitmq.ingest.stuck_after_minutes');

        if ($run !== null && $run->started_at->lt(now()->subMinutes($minutes))) {
            $this->runs->finish($run, IngestionStatus::Failed, RunStats::fromArray($run->stats), 'timeout');
        }
    }
}
