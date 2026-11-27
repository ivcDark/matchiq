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
        private readonly ?ApiPlayerResolver $apiPlayers = null,
    ) {}

    /** Игроки названы числовыми id SStats /Games (people_source = sstats_api), а не внешними id провайдера. */
    public function resolvesApiPlayers(): bool
    {
        return $this->apiPlayers !== null;
    }

    /**
     * Команда строки деталей: по внешнему id, а если его нет (SStats не всегда отдаёт flashId команды) — по стороне матча.
     *
     * @throws IngestionEntityException
     */
    public function teamOf(array $row, bool $required = true): ?int
    {
        $externalId = $row['team_external_id'] ?? null;
        if (is_string($externalId) && $externalId !== '') {
            return $this->team($externalId);
        }

        return match ($row['side'] ?? null) {
            'home' => $this->match->home_team_id,
            'away' => $this->match->away_team_id,
            default => $required ? throw IngestionEntityException::invalid('у строки нет ни team_external_id, ни side') : null,
        };
    }

    public function opposite(int $teamId): ?int
    {
        return match ($teamId) {
            $this->match->home_team_id => $this->match->away_team_id,
            $this->match->away_team_id => $this->match->home_team_id,
            default => null,
        };
    }

    /** Игрок по числовому id и имени (null — не опознан). Только в режиме sstats_api. */
    public function apiPlayer(mixed $apiId, mixed $name, ?int $teamId): ?int
    {
        return $this->apiPlayers?->resolve(
            is_string($apiId) ? $apiId : null,
            is_string($name) ? $name : null,
            $teamId,
        );
    }

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
