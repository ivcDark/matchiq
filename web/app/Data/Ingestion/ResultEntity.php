<?php

namespace App\Data\Ingestion;

use App\Enums\ExternalEntityType;

final readonly class ResultEntity
{
    public function __construct(
        public ExternalEntityType $type,
        public string $externalId,
        public ?string $externalUrl,
        public array $data,
    ) {}

    /** Порядок зависимостей: справочники → сезоны → команды/стадии. */
    public function rank(): int
    {
        return match ($this->type) {
            ExternalEntityType::Country => 1,
            ExternalEntityType::Competition => 2,
            ExternalEntityType::Season => 3,
            ExternalEntityType::Team => 4,
            ExternalEntityType::Stage => 5,
            default => 9,
        };
    }
}
