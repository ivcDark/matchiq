<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\AbsenceType;
use App\Enums\ExternalEntityType;
use App\Enums\PlayerPosition;
use App\Enums\SourceProviderCode;
use App\Enums\UpsertOutcome;
use App\Repositories\ExternalIdRepository;
use App\Repositories\PlayerAbsenceRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\SquadMembershipRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;

/**
 * Актуальный состав клуба (`squad_memberships`). Состав приходит целиком: игроки из списка получают действующую заявку
 * (создаётся или обновляется позиция), заявки игроков, которых в списке больше нет, закрываются датой синхронизации.
 * Игроки ссылаются на уже загруженные сущности player; неизвестные пропускаются и считаются в `squad_players.skipped`.
 *
 * Из /Players/list (`people_source = sstats_api`) команда и игроки названы числовыми id SStats, а у игроков приходят
 * текущие травмы и дисквалификации: они синхронизируются в `player_absences` команды (`player_absences.*`).
 */
class SquadUpserter implements EntityUpserter
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly PlayerRepository $players,
        private readonly ExternalIdRepository $externalIds,
        private readonly SquadMembershipRepository $memberships,
        private readonly PlayerAbsenceRepository $absences,
        private readonly SstatsApiIds $apiIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Squad;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $peopleSource = $entity->data['people_source'] ?? null;
        if ($peopleSource !== null && $peopleSource !== SourceProviderCode::SstatsApi->value) {
            throw IngestionEntityException::invalid('неизвестный people_source', ['people_source' => $peopleSource]);
        }
        $fromApi = $peopleSource !== null;
        $idsProvider = $fromApi ? $this->apiIds->providerId() : $context->providerId;

        $teamId = $this->externalIds->requireEntityId($idsProvider, ExternalEntityType::Team, $entity->externalId);
        $team = $this->teams->find($teamId)
            ?? throw IngestionEntityException::missingReference(ExternalEntityType::Team->value, $entity->externalId);

        $rows = [];
        $absenceRows = [];
        foreach ((array) ($entity->data['players'] ?? []) as $row) {
            $id = trim((string) ($row['player_external_id'] ?? ''));
            if ($id !== '') {
                $rows[$id] = PlayerPosition::tryFrom((string) ($row['position'] ?? ''));
                $absenceRows[$id] = (array) ($row['absences'] ?? []);
            }
        }
        if ($rows === []) {
            throw IngestionEntityException::invalid('в составе нет игроков');
        }

        $playerIds = $this->externalIds->entityIdsFor($idsProvider, ExternalEntityType::Player, array_keys($rows));
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

        $children = ['squad_players' => $counts];
        if ($fromApi) {
            $absenceCounts = $this->absences->syncForTeam($team->id, $this->absencesOf($absenceRows, $playerIds));
            if ($absenceCounts !== []) {
                $children['player_absences'] = $absenceCounts;
            }
        }

        return new UpsertResult($outcome, $team->id, $children);
    }

    /**
     * @param  array<string, array>  $rows  внешний id игрока → absences
     * @param  array<string, int>  $playerIds
     * @return list<array{player_id: int, type: AbsenceType, reason: ?string, since: ?string, expected_return: ?string}>
     */
    private function absencesOf(array $rows, array $playerIds): array
    {
        $result = [];
        foreach ($rows as $externalId => $absences) {
            $playerId = $playerIds[$externalId] ?? null;
            if ($playerId === null) {
                continue;
            }
            foreach ($absences as $absence) {
                $type = is_array($absence) ? AbsenceType::tryFrom((string) ($absence['type'] ?? '')) : null;
                if ($type === null) {
                    continue;
                }
                $result[] = [
                    'player_id' => $playerId,
                    'type' => $type,
                    'reason' => mb_substr(trim((string) ($absence['reason'] ?? '')), 0, 255) ?: null,
                    'since' => $this->date($absence['since'] ?? null),
                    'expected_return' => $this->date($absence['expected_return'] ?? null),
                ];
            }
        }

        return $result;
    }

    private function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }
}
