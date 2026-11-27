<?php

namespace App\Domains\Ingestion;

use App\Enums\UpsertOutcome;

/**
 * Счётчики прогона (`ingestion_runs.stats`): created/updated/unchanged/skipped/failed + by_entity.
 * Чистая логика без БД.
 */
final class RunStats
{
    private const COUNTERS = ['created', 'updated', 'unchanged', 'skipped', 'failed'];

    private function __construct(private array $data) {}

    public static function fromArray(?array $stats): self
    {
        $data = $stats ?? [];
        $data['by_entity'] = $data['by_entity'] ?? [];
        $data['context'] = $data['context'] ?? [];

        foreach (self::COUNTERS as $counter) {
            $data[$counter] = (int) ($data[$counter] ?? 0);
        }
        $data['unmatched'] = (int) ($data['unmatched'] ?? 0);

        return new self($data);
    }

    public static function empty(array $context = []): self
    {
        return self::fromArray(['context' => $context]);
    }

    public function recordOutcome(string $entityType, UpsertOutcome $outcome): void
    {
        $this->bump($entityType, $outcome->value);
    }

    /**
     * Дочерние записи сущности (строки составов, событий и т.п.): только в `by_entity`, общие счётчики не растут.
     *
     * @param  array<string, array<string, int>>  $children
     */
    public function recordChildren(array $children): void
    {
        foreach ($children as $name => $outcomes) {
            foreach ($outcomes as $outcome => $count) {
                $this->bumpEntityOnly($name, $outcome, $count);
            }
        }
    }

    public function recordFailure(?string $entityType): void
    {
        $this->bump($entityType ?? 'unknown', 'failed');
    }

    /** Сводит счётчики другого набора (по `by_entity`) в этот. */
    public function merge(self $other): void
    {
        foreach ($other->data['by_entity'] as $type => $counters) {
            foreach (self::COUNTERS as $counter) {
                $count = (int) ($counters[$counter] ?? 0);
                if (isset($other->data['children'][$type])) {
                    $this->bumpEntityOnly($type, $counter, $count);
                } else {
                    $this->bump($type, $counter, $count);
                }
            }
        }
    }

    public function context(string $key, mixed $default = null): mixed
    {
        return $this->data['context'][$key] ?? $default;
    }

    public function setContext(string $key, mixed $value): void
    {
        $this->data['context'][$key] = $value;
    }

    public function count(string $counter): int
    {
        return $this->data[$counter];
    }

    public function succeeded(): int
    {
        return $this->data['created'] + $this->data['updated'] + $this->data['unchanged'] + $this->data['skipped'];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private function bump(string $entityType, string $counter, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }

        $this->data[$counter] += $by;

        $byEntity = $this->data['by_entity'][$entityType] ?? array_fill_keys(self::COUNTERS, 0);
        $byEntity[$counter] += $by;
        $this->data['by_entity'][$entityType] = $byEntity;
    }

    private function bumpEntityOnly(string $name, string $counter, int $by): void
    {
        if ($by === 0) {
            return;
        }

        $byEntity = $this->data['by_entity'][$name] ?? array_fill_keys(self::COUNTERS, 0);
        $byEntity[$counter] += $by;
        $this->data['by_entity'][$name] = $byEntity;
        $this->data['children'][$name] = true;
    }
}
