<?php

namespace App\Data\Ingestion;

use App\Enums\ExternalEntityType;
use App\Enums\IngestionMessageKind;
use App\Enums\IngestionPhase;
use App\Services\Ingestion\Exceptions\InvalidResultMessage;

/** Сообщение результата collector → Laravel: batch | done | failed. */
final readonly class ResultMessage
{
    /**
     * @param  list<ResultEntity>  $entities
     * @param  list<ResultError>  $errors
     */
    public function __construct(
        public IngestionMessageKind $kind,
        public int $runId,
        public string $messageId,
        public string $jobType,
        public string $provider,
        public int $seq = 0,
        public array $entities = [],
        public array $errors = [],
        public int $batchesTotal = 0,
        public array $source = [],
        public string $message = '',
        public bool $retryable = false,
    ) {}

    /** @throws InvalidResultMessage */
    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw new InvalidResultMessage('сообщение не является JSON-объектом');
        }

        $expected = (int) config('rabbitmq.ingest.schema_version');
        if (($raw['schema_version'] ?? null) !== $expected) {
            throw new InvalidResultMessage('неподдерживаемая schema_version: '.json_encode($raw['schema_version'] ?? null));
        }

        $kind = IngestionMessageKind::tryFrom((string) ($raw['kind'] ?? ''));
        if ($kind === null) {
            throw new InvalidResultMessage('неизвестный kind: '.json_encode($raw['kind'] ?? null));
        }

        $runId = $raw['run_id'] ?? null;
        if (! is_int($runId) || $runId <= 0) {
            throw new InvalidResultMessage('run_id обязателен');
        }

        $entities = [];
        $errors = [];

        if ($kind === IngestionMessageKind::Batch) {
            foreach ((array) ($raw['entities'] ?? []) as $entity) {
                $entities[] = self::entityFrom($entity);
            }
            foreach ((array) ($raw['errors'] ?? []) as $error) {
                $errors[] = self::errorFrom($error);
            }
        }

        return new self(
            kind: $kind,
            runId: $runId,
            messageId: (string) ($raw['message_id'] ?? ''),
            jobType: (string) ($raw['job_type'] ?? ''),
            provider: (string) ($raw['provider'] ?? ''),
            seq: (int) ($raw['seq'] ?? 0),
            entities: $entities,
            errors: $errors,
            batchesTotal: (int) ($raw['batches_total'] ?? 0),
            source: (array) ($raw['source'] ?? []),
            message: (string) ($raw['message'] ?? ''),
            retryable: (bool) ($raw['retryable'] ?? false),
        );
    }

    private static function entityFrom(mixed $entity): ResultEntity
    {
        if (! is_array($entity)) {
            throw new InvalidResultMessage('entity не является объектом');
        }

        $type = ExternalEntityType::tryFrom((string) ($entity['entity_type'] ?? ''));
        $externalId = (string) ($entity['external_id'] ?? '');

        if ($type === null || $externalId === '' || ! is_array($entity['data'] ?? null)) {
            throw new InvalidResultMessage('некорректная entity: нужны entity_type, external_id, data');
        }

        return new ResultEntity($type, $externalId, $entity['external_url'] ?? null, $entity['data']);
    }

    private static function errorFrom(mixed $error): ResultError
    {
        if (! is_array($error)) {
            throw new InvalidResultMessage('error не является объектом');
        }

        $phase = IngestionPhase::tryFrom((string) ($error['phase'] ?? '')) ?? IngestionPhase::Normalize;

        return new ResultError(
            entityType: isset($error['entity_type']) ? (string) $error['entity_type'] : null,
            externalId: isset($error['external_id']) ? (string) $error['external_id'] : null,
            phase: $phase,
            message: (string) ($error['message'] ?? ''),
            context: (array) ($error['context'] ?? []),
        );
    }
}
