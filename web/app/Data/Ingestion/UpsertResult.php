<?php

namespace App\Data\Ingestion;

use App\Enums\UpsertOutcome;

final readonly class UpsertResult
{
    /**
     * @param  array<string, array<string, int>>  $children  Дочерние записи сущности: имя → [исходы → число], идут в `by_entity` прогона.
     */
    public function __construct(
        public UpsertOutcome $outcome,
        public int $entityId,
        public array $children = [],
    ) {}
}
