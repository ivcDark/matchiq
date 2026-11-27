<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\MatchEventType;
use App\Enums\MatchSide;
use App\Enums\PlayerPosition;
use App\Enums\StatsPeriod;
use App\Enums\UpsertOutcome;
use App\Repositories\ExternalIdRepository;
use App\Repositories\MatchEventRepository;
use App\Repositories\MatchLineupRepository;
use App\Repositories\MatchRepository;
use App\Repositories\MatchStatsRepository;
use App\Repositories\MatchTeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use Illuminate\Support\Collection;

/**
 * Составы, события и статистика одного матча. Игроки, тренеры и команды ссылаются на уже загруженные сущности.
 * Секция из пустого списка ничего не удаляет; непустая синхронизируется целиком (строки, которых больше нет в источнике, удаляются).
 * Сущность атомарна: любая невалидная ссылка откатывает весь матч.
 */
class MatchDetailsUpserter implements EntityUpserter
{
    private const SMALLINT_MAX = 32767;

    public function __construct(
        private readonly MatchRepository $matches,
        private readonly MatchTeamRepository $matchTeams,
        private readonly MatchLineupRepository $lineups,
        private readonly MatchEventRepository $events,
        private readonly MatchStatsRepository $stats,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::MatchDetails;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $data = $entity->data;
        $matchId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Match, $entity->externalId);
        $match = $this->matches->find($matchId)
            ?? throw IngestionEntityException::missingReference(ExternalEntityType::Match->value, $entity->externalId);

        $refs = new MatchReferences($this->externalIds, $context->providerId, $match);

        $children = [];
        $this->syncTeams($matchId, $this->list($data, 'teams'), $refs, $children);
        $this->syncLineups($matchId, $this->list($data, 'lineup'), $refs, $children);
        $this->syncEvents($matchId, $this->list($data, 'events'), $refs, $children);
        $this->syncTeamStats($matchId, $this->list($data, 'team_stats'), $refs, $children);
        $this->syncPlayerStats($matchId, $this->list($data, 'player_stats'), $refs, $children);

        return new UpsertResult($this->aggregate($children), $matchId, $children);
    }

    /** @param  array<string, array<string, int>>  $children */
    private function aggregate(array $children): UpsertOutcome
    {
        $total = fn (string $outcome): int => array_sum(array_column($children, $outcome));

        if ($total('created') > 0 && $total('updated') === 0 && $total('unchanged') === 0) {
            return UpsertOutcome::Created;
        }

        return $total('created') + $total('updated') > 0 ? UpsertOutcome::Updated : UpsertOutcome::Unchanged;
    }

    /** @return list<array> */
    private function list(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw IngestionEntityException::invalid("секция {$key} должна быть списком");
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw IngestionEntityException::invalid("строка секции {$key} не является объектом");
            }
        }

        return $rows;
    }

    /**
     * @param  array<int|string, callable>  $rows  ключ строки → функция upsert(?existing)
     * @param  array<string, array<string, int>>  $children
     */
    private function sync(string $child, Collection $existing, array $rows, array &$children, callable $delete): void
    {
        if ($rows === []) {
            return;
        }

        foreach ($rows as $key => $upsert) {
            [, $outcome] = $upsert($existing->get($key));
            $children[$child][$outcome->value] = ($children[$child][$outcome->value] ?? 0) + 1;
        }

        $stale = $existing->reject(fn ($model, $key) => array_key_exists($key, $rows))->pluck('id')->all();
        if ($stale !== []) {
            $delete($stale);
            $children[$child][UpsertOutcome::Updated->value] = ($children[$child][UpsertOutcome::Updated->value] ?? 0) + count($stale);
        }
    }

    private function syncTeams(int $matchId, array $list, MatchReferences $refs, array &$children): void
    {
        $rows = [];
        foreach ($list as $row) {
            $side = MatchSide::tryFrom((string) ($row['side'] ?? ''))
                ?? throw IngestionEntityException::invalid('у команды матча неизвестная сторона', ['side' => $row['side'] ?? null]);
            $teamId = $refs->team($row['team_external_id'] ?? null);
            $expected = $side === MatchSide::Home ? $refs->match()->home_team_id : $refs->match()->away_team_id;
            if ($teamId !== $expected) {
                throw IngestionEntityException::conflict('команда не соответствует стороне матча', ['side' => $side->value, 'team_external_id' => $row['team_external_id']]);
            }
            if (isset($rows[$side->value])) {
                throw IngestionEntityException::invalid('сторона матча повторяется', ['side' => $side->value]);
            }

            $coachId = $refs->coach($row['coach_external_id'] ?? null);
            $formation = trim((string) ($row['formation'] ?? ''));
            if (mb_strlen($formation) > 16) {
                throw IngestionEntityException::invalid('слишком длинная формация', ['formation' => $formation]);
            }
            $rating = $this->decimal($row['rating'] ?? null, 'rating', 100);

            $rows[$side->value] = fn ($existing) => $this->matchTeams->upsert($existing, $matchId, $teamId, $side, $coachId, $formation !== '' ? $formation : null, $rating);
        }

        $this->sync('match_team', $this->matchTeams->forMatch($matchId), $rows, $children, fn (array $ids) => $this->matchTeams->delete($ids));
    }

    private function syncLineups(int $matchId, array $list, MatchReferences $refs, array &$children): void
    {
        $rows = [];
        foreach ($list as $row) {
            $playerId = $refs->player($row['player_external_id'] ?? null);
            $teamId = $refs->team($row['team_external_id'] ?? null);
            if (isset($rows[$playerId])) {
                throw IngestionEntityException::invalid('игрок повторяется в составах матча', ['player_external_id' => $row['player_external_id']]);
            }

            $position = PlayerPosition::tryFrom((string) ($row['position'] ?? ''));
            $shirt = $this->int($row['shirt_number'] ?? null, 'shirt_number');
            $line = $this->int($row['line'] ?? null, 'line');
            $sortOrder = $this->int($row['sort_order'] ?? 0, 'sort_order') ?? 0;
            $minuteIn = $this->int($row['minute_in'] ?? null, 'minute_in');
            $minuteOut = $this->int($row['minute_out'] ?? null, 'minute_out');
            $starter = (bool) ($row['is_starter'] ?? false);
            $captain = (bool) ($row['is_captain'] ?? false);

            $rows[$playerId] = fn ($existing) => $this->lineups->upsert(
                $existing, $matchId, $teamId, $playerId, $starter, $captain, $shirt, $position,
                $line !== null ? (string) $line : null, $sortOrder, $minuteIn, $minuteOut,
            );
        }

        $this->sync('match_lineup', $this->lineups->forMatch($matchId), $rows, $children, fn (array $ids) => $this->lineups->delete($ids));
    }

    private function syncEvents(int $matchId, array $list, MatchReferences $refs, array &$children): void
    {
        $rows = [];
        foreach ($list as $row) {
            $type = MatchEventType::tryFrom((string) ($row['type'] ?? ''))
                ?? throw IngestionEntityException::invalid('у события неизвестный type', ['type' => $row['type'] ?? null]);
            $minute = $this->int($row['minute'] ?? null, 'minute')
                ?? throw IngestionEntityException::invalid('у события нет minute');
            $sortOrder = $this->int($row['sort_order'] ?? null, 'sort_order')
                ?? throw IngestionEntityException::invalid('у события нет sort_order');
            if (isset($rows[$sortOrder])) {
                throw IngestionEntityException::invalid('sort_order события повторяется', ['sort_order' => $sortOrder]);
            }

            $teamExternalId = $row['team_external_id'] ?? null;
            $teamId = is_string($teamExternalId) && $teamExternalId !== '' ? $refs->team($teamExternalId) : null;
            $playerId = $refs->optionalPlayer($row['player_external_id'] ?? null);
            $relatedId = $refs->optionalPlayer($row['related_player_external_id'] ?? null);
            $added = $this->int($row['added_minute'] ?? null, 'added_minute');
            $scoreHome = $this->int($row['score_home'] ?? null, 'score_home');
            $scoreAway = $this->int($row['score_away'] ?? null, 'score_away');
            $details = is_array($row['details'] ?? null) && $row['details'] !== [] ? $row['details'] : null;

            $rows[$sortOrder] = fn ($existing) => $this->events->upsert(
                $existing, $matchId, $teamId, $playerId, $relatedId, $type, $minute, $added, $scoreHome, $scoreAway, $sortOrder, $details,
            );
        }

        $this->sync('match_event', $this->events->forMatch($matchId), $rows, $children, fn (array $ids) => $this->events->delete($ids));
    }

    private function syncTeamStats(int $matchId, array $list, MatchReferences $refs, array &$children): void
    {
        $rows = [];
        foreach ($list as $row) {
            $teamId = $refs->team($row['team_external_id'] ?? null);
            $period = StatsPeriod::tryFrom((string) ($row['period'] ?? ''))
                ?? throw IngestionEntityException::invalid('у командной статистики неизвестный period', ['period' => $row['period'] ?? null]);
            $key = $teamId.':'.$period->value;
            if (isset($rows[$key])) {
                throw IngestionEntityException::invalid('командная статистика повторяется', ['key' => $key]);
            }

            $metrics = [
                'xg' => $this->decimal($row['xg'] ?? null, 'xg', 1000),
                'xga' => $this->decimal($row['xga'] ?? null, 'xga', 1000),
                'possession_pct' => $this->decimal($row['possession_pct'] ?? null, 'possession_pct', 1000),
                'pass_accuracy_pct' => $this->decimal($row['pass_accuracy_pct'] ?? null, 'pass_accuracy_pct', 1000),
                'extra' => $this->extra($row['extra'] ?? null),
            ];
            foreach (['shots', 'shots_on_target', 'corners', 'fouls', 'offsides', 'yellow_cards', 'red_cards', 'passes', 'aerials_won', 'saves'] as $column) {
                $metrics[$column] = $this->int($row[$column] ?? null, $column);
            }

            $rows[$key] = fn ($existing) => $this->stats->upsertTeamStat($existing, $matchId, $teamId, $period, $metrics);
        }

        $this->sync('match_team_stat', $this->stats->teamStatsForMatch($matchId), $rows, $children, fn (array $ids) => $this->stats->deleteTeamStats($ids));
    }

    private function syncPlayerStats(int $matchId, array $list, MatchReferences $refs, array &$children): void
    {
        $rows = [];
        foreach ($list as $row) {
            $playerId = $refs->player($row['player_external_id'] ?? null);
            $teamId = $refs->team($row['team_external_id'] ?? null);
            if (isset($rows[$playerId])) {
                throw IngestionEntityException::invalid('статистика игрока повторяется', ['player_external_id' => $row['player_external_id']]);
            }

            $metrics = [
                'xg' => $this->decimal($row['xg'] ?? null, 'xg', 1000),
                'xa' => $this->decimal($row['xa'] ?? null, 'xa', 1000),
                'rating' => $this->decimal($row['rating'] ?? null, 'rating', 100),
                'is_motm' => (bool) ($row['is_motm'] ?? false),
                'extra' => $this->extra($row['extra'] ?? null),
            ];
            foreach (['minutes', 'shots', 'shots_on_target'] as $column) {
                $metrics[$column] = $this->int($row[$column] ?? null, $column);
            }
            foreach (['goals', 'assists', 'penalties_scored', 'yellow_cards', 'red_cards'] as $column) {
                $metrics[$column] = $this->int($row[$column] ?? null, $column) ?? 0;
            }

            $rows[$playerId] = fn ($existing) => $this->stats->upsertPlayerStat($existing, $matchId, $teamId, $playerId, $metrics);
        }

        $this->sync('match_player_stat', $this->stats->playerStatsForMatch($matchId), $rows, $children, fn (array $ids) => $this->stats->deletePlayerStats($ids));
    }

    private function int(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > self::SMALLINT_MAX) {
            throw IngestionEntityException::invalid("некорректное значение {$field}", [$field => $value]);
        }

        return $value;
    }

    /** Числа в колонки decimal(_, 2): округляем до сотых, предел — по ширине колонки. */
    private function decimal(mixed $value, string $field, int $limit): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || abs($value) >= $limit) {
            throw IngestionEntityException::invalid("некорректное значение {$field}", [$field => $value]);
        }

        return round((float) $value, 2);
    }

    private function extra(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}
