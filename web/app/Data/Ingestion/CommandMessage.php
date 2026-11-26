<?php

namespace App\Data\Ingestion;

use App\Enums\IngestionKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Команда Laravel → collector (конверт + payload, см. docs/architecture/ingestion.md). */
final readonly class CommandMessage
{
    public function __construct(
        public int $runId,
        public IngestionKind $jobType,
        public string $provider,
        public array $payload,
        public string $messageId = '',
    ) {}

    public function toArray(): array
    {
        return [
            'schema_version' => (int) config('rabbitmq.ingest.schema_version'),
            'message_id' => $this->messageId !== '' ? $this->messageId : (string) Str::uuid(),
            'run_id' => $this->runId,
            'job_type' => $this->jobType->value,
            'provider' => $this->provider,
            'sent_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'payload' => $this->payload === [] ? new \stdClass : $this->payload,
        ];
    }
}
