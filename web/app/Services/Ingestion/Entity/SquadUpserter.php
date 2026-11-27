<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\PlayerPosition;
use App\Enums\UpsertOutcome;
use App\Repositories\ExternalIdRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\SquadMembershipRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Актуальный состав клуба (`squad_memberships`). Состав приходит целиком: игроки из списка получают действующую заявку
 * (создаётся или обновляется позиция), заявки игроков, которых в списке больше нет, закрываются датой синхронизации.
 * Игроки ссылаются на уже загруженные сущности player; неизвестные пропускаются и считаются в `squad_players.skipped`.
 */
class SquadUpserter implements EntityUpserter
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly PlayerRepository $players,
        private readonly ExternalIdRepository $externalIds,
        private readonly SquadMembershipRepository $memberships,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Squad;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $teamId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Team, $entity->externalId);
        $team = $this->teams->find($teamId)
            ?? throw IngestionEntityException::missingReference(ExternalEntityType::Team->value, $entity->externalId);

        $rows = [];
        foreach ((array) ($entity->data['players'] ?? []) as $row) {
            $id = trim((string) ($row['player_external_id'] ?? ''));
            if ($id !== '') {
                $rows[$id] = PlayerPosition::tryFrom((string) ($row['position'] ?? ''));
            }
        }
        if ($rows === []) {
            throw IngestionEntityException::invalid('в составе нет игроков');
        }

        $playerIds = $this->externalIds->entityIdsFor($context->providerId, ExternalEntityType::Player, array_keys($rows));
        if ($playerIds === []) {
            throw IngestionEntityException::missingReference(ExternalEntityType::Player->value, (string) array_key_first($rows));
        }

        $today = now(config('app.timezone'))->startOfDay();
        $open = $this->memberships->openForTeam($team->id);
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => count($rows) - count($playerIds)];

        $current = [];
        foreach ($rows as $externalId => $position) {
            $playerId = $playerIds[$externalId] ?? null;
            if ($playerId === null) {
                continue;
            }
            $position ??= $this->players->find($playerId)?->position;
            $outcome = $this->memberships->join($team->id, $playerId, $position, $open->get($playerId), $today);
            $counts[$outcome->value]++;
            $current[$playerId] = true;
        }

        $left = $open->reject(fn ($m) => isset($current[$m->player_id]))->pluck('id')->all();
        $counts['updated'] += $this->memberships->close($left, $today);
        $this->memberships->closeElsewhere(array_keys($current), $team->id, $today);

        $changed = $counts['created'] + $counts['updated'] > 0;
        $outcome = match (true) {
            ! $changed => UpsertOutcome::Unchanged,
            $counts['updated'] === 0 && $counts['unchanged'] === 0 => UpsertOutcome::Created,
            default => UpsertOutcome::Updated,
        };

        return new UpsertResult($outcome, $team->id, ['squad_players' => $counts]);
    }
}
