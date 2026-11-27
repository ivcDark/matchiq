<?php

namespace App\Services\Ingestion\Entity;

use App\Enums\ExternalEntityType;
use App\Models\FootballMatch;
use App\Repositories\ExternalIdRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/** Разрешение внешних id из деталей матча в локальные: команды обязаны играть в матче, игроки и тренеры — быть загружены. */
final class MatchReferences
{
    public function __construct(
        private readonly ExternalIdRepository $externalIds,
        private readonly int $providerId,
        private readonly FootballMatch $match,
    ) {}

    public function match(): FootballMatch
    {
        return $this->match;
    }

    public function team(mixed $externalId): int
    {
        $id = $this->externalIds->requireEntityId($this->providerId, ExternalEntityType::Team, is_string($externalId) ? $externalId : null);
        if (! in_array($id, [$this->match->home_team_id, $this->match->away_team_id], true)) {
            throw IngestionEntityException::conflict('команда не играет в этом матче', ['team_external_id' => $externalId]);
        }

        return $id;
    }

    public function player(mixed $externalId): int
    {
        return $this->externalIds->requireEntityId($this->providerId, ExternalEntityType::Player, is_string($externalId) ? $externalId : null);
    }

    public function optionalPlayer(mixed $externalId): ?int
    {
        return is_string($externalId) && $externalId !== '' ? $this->player($externalId) : null;
    }

    public function coach(mixed $externalId): ?int
    {
        return is_string($externalId) && $externalId !== ''
            ? $this->externalIds->requireEntityId($this->providerId, ExternalEntityType::Coach, $externalId)
            : null;
    }
}
