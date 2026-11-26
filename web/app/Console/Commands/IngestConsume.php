<?php

namespace App\Console\Commands;

use App\Data\Ingestion\ResultMessage;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Services\Ingestion\Exceptions\InvalidResultMessage;
use App\Services\Ingestion\Messaging\AmqpIngestionBroker;
use Illuminate\Console\Command;

class IngestConsume extends Command
{
    protected $signature = 'ingest:consume
        {--max-messages=0 : остановиться после N сообщений (0 — работать бесконечно)}
        {--max-time=0 : остановиться через N секунд (0 — без лимита)}';

    protected $description = 'Воркер: читает ingest.results и сохраняет результат collector через репозитории';

    private bool $stop = false;

    public function handle(AmqpIngestionBroker $broker, ApplyIngestionResult $apply): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $deadline = (int) $this->option('max-time') > 0 ? time() + (int) $this->option('max-time') : null;
        $this->info('ingest:consume слушает '.config('rabbitmq.ingest.results.queue'));

        $processed = $broker->consumeResults(
            function (string $body) use ($apply): void {
                try {
                    $message = ResultMessage::fromArray(json_decode($body, true, 512, JSON_THROW_ON_ERROR));
                } catch (\JsonException $e) {
                    throw new InvalidResultMessage('некорректный JSON: '.$e->getMessage());
                }

                $apply->handle($message);
                $this->line("run #{$message->runId} {$message->kind->value}".($message->seq ? " seq={$message->seq}" : ''));
            },
            fn () => $this->stop || ($deadline !== null && time() >= $deadline),
            (int) $this->option('max-messages'),
        );

        $broker->close();
        $this->info("Обработано сообщений: {$processed}");

        return self::SUCCESS;
    }
}
