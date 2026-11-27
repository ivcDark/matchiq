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

    /** Порядок зависимостей: справочники → сезоны → команды/стадии → группы/туры → участие в стадиях → матчи → игроки/тренеры → детали матча и составы клубов. */
    public function rank(): int
    {
        return match ($this->type) {
            ExternalEntityType::Country => 1,
            ExternalEntityType::Competition => 2,
            ExternalEntityType::Season => 3,
            ExternalEntityType::Team => 4,
            ExternalEntityType::Stage => 5,
            ExternalEntityType::StageGroup, ExternalEntityType::Round => 6,
            ExternalEntityType::StageTeam => 7,
            ExternalEntityType::Match => 8,
            ExternalEntityType::Player, ExternalEntityType::Coach => 9,
            ExternalEntityType::MatchDetails, ExternalEntityType::Squad => 10,
            ExternalEntityType::ExternalRefs => 11,
            default => 12,
        };
    }
}
