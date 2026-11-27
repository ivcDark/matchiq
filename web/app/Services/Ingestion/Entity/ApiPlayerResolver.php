<?php

namespace App\Services\Ingestion\Entity;

use App\Enums\ExternalEntityType;
use App\Repositories\ExternalIdRepository;
use App\Repositories\PlayerRepository;
use App\Support\PersonName;

/**
 * Игроки деталей матча из /Games названы числовыми id SStats, а в БД они лежат под flash-id (провайдер sstats).
 * Порядок поиска: известный числовой id (провайдер sstats_api) → имя среди игроков команды матча. Найденного по имени
 * привязываем (sstats_api), и дальше он находится по id. Неоднозначное или неизвестное — null.
 */
final class ApiPlayerResolver
{
    /** @var array<int, array<int, string>> team_id → [player_id → имя] */
    private array $teamPlayers = [];

    public function __construct(
        private readonly ExternalIdRepository $externalIds,
        private readonly PlayerRepository $players,
        private readonly int $apiProviderId,
        private readonly int $runId,
    ) {}

    public function resolve(?string $apiId, ?string $name, ?int $teamId): ?int
    {
        $apiId = trim((string) $apiId);
        $name = trim((string) $name);

        if ($apiId !== '') {
            $known = $this->externalIds->findEntityId($this->apiProviderId, ExternalEntityType::Player, $apiId);
            if ($known !== null) {
                return $known;
            }
        }
        if ($name === '' || $teamId === null) {
            return null;
        }

        $this->teamPlayers[$teamId] ??= $this->players->namesForTeam($teamId);
        $playerId = PersonName::findUnique($name, $this->teamPlayers[$teamId]);

        if ($playerId !== null && $apiId !== '') {
            $this->link($apiId, $playerId);
        }

        return $playerId;
    }

    /** У игрока уже есть другой числовой id (дубль в источнике) — не перезаписываем его, имя находится и без привязки. */
    private function link(string $apiId, int $playerId): void
    {
        if ($this->externalIds->externalIdFor($this->apiProviderId, ExternalEntityType::Player, $playerId) !== null) {
            return;
        }

        $this->externalIds->link($this->apiProviderId, ExternalEntityType::Player, $apiId, $playerId, $this->runId);
    }
}
