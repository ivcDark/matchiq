<?php

namespace App\Services\Ingestion\Entity;

use App\Data\Ingestion\ResultEntity;
use App\Data\Ingestion\UpsertContext;
use App\Data\Ingestion\UpsertResult;
use App\Enums\ExternalEntityType;
use App\Enums\MatchPeriod;
use App\Enums\MatchStatus;
use App\Enums\RoundType;
use App\Models\FootballMatch;
use App\Repositories\ExternalIdRepository;
use App\Repositories\MatchRepository;
use App\Repositories\RoundRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\StageRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use Illuminate\Support\Carbon;
use Throwable;

class MatchUpserter implements EntityUpserter
{
    public function __construct(
        private readonly MatchRepository $matches,
        private readonly RoundRepository $rounds,
        private readonly SeasonRepository $seasons,
        private readonly StageRepository $stages,
        private readonly ExternalIdRepository $externalIds,
    ) {}

    public function type(): ExternalEntityType
    {
        return ExternalEntityType::Match;
    }

    public function upsert(ResultEntity $entity, UpsertContext $context): UpsertResult
    {
        $data = $entity->data;

        $status = MatchStatus::tryFrom((string) ($data['status'] ?? ''))
            ?? throw IngestionEntityException::invalid('у матча неизвестный status', ['status' => $data['status'] ?? null]);
        $period = isset($data['period']) ? MatchPeriod::tryFrom((string) $data['period']) : null;

        try {
            $kickoff = Carbon::parse((string) ($data['kickoff_at'] ?? ''));
        } catch (Throwable) {
            throw IngestionEntityException::invalid('у матча некорректный kickoff_at', ['kickoff_at' => $data['kickoff_at'] ?? null]);
        }

        $seasonId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Season, $data['season_external_id'] ?? null);
        $stageId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Stage, $data['stage_external_id'] ?? null);
        $homeId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Team, $data['home_team_external_id'] ?? null);
        $awayId = $this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Team, $data['away_team_external_id'] ?? null);

        if ($homeId === $awayId) {
            throw IngestionEntityException::invalid('хозяева и гости — одна команда', ['team_id' => $homeId]);
        }

        $season = $this->seasons->find($seasonId);
        $stage = $this->stages->find($stageId);
        if ($season === null || $stage === null || $stage->season_id !== $season->id) {
            throw IngestionEntityException::conflict('стадия не принадлежит сезону матча', [
                'season_external_id' => $data['season_external_id'] ?? null,
                'stage_external_id' => $data['stage_external_id'] ?? null,
            ]);
        }

        $existing = $this->resolveExisting($entity, $context, $seasonId, $homeId, $awayId, $kickoff);

        [$match, $outcome] = $this->matches->upsert(
            $existing,
            $season->competition_id,
            $season->id,
            $stage->id,
            $this->resolveRoundId($stage->id, $stage->type->hasStandings(), $data),
            $homeId,
            $awayId,
            $kickoff,
            $status,
            $period,
            [
                'home' => $this->score($data, 'home_goals'),
                'away' => $this->score($data, 'away_goals'),
                'home_ht' => $this->score($data, 'home_goals_ht'),
                'away_ht' => $this->score($data, 'away_goals_ht'),
                'home_et' => $this->score($data, 'home_goals_et'),
                'away_et' => $this->score($data, 'away_goals_et'),
                'home_pens' => $this->score($data, 'home_pens'),
                'away_pens' => $this->score($data, 'away_pens'),
            ],
        );

        $this->externalIds->track($context->providerId, $this->type(), $entity->externalId, $match->id, $context->runId, $entity->externalUrl);

        return new UpsertResult($outcome, $match->id);
    }

    /** По external_id; новый — страховка от дублей: та же пара в сезоне в ±1 день, ещё не привязанная к источнику. */
    private function resolveExisting(ResultEntity $entity, UpsertContext $context, int $seasonId, int $homeId, int $awayId, Carbon $kickoff): ?FootballMatch
    {
        $existingId = $this->externalIds->findEntityId($context->providerId, $this->type(), $entity->externalId);
        if ($existingId !== null) {
            return $this->matches->find($existingId);
        }

        return $this->matches->findByTeamsAround($seasonId, $homeId, $awayId, $kickoff)
            ->first(fn (FootballMatch $m) => ! $this->externalIds->isLinked($context->providerId, $this->type(), $m->id));
    }

    private function resolveRoundId(int $stageId, bool $isTable, array $data): ?int
    {
        $number = $data['round_number'] ?? null;
        if (! is_int($number) || $number < 1) {
            return null;
        }

        $name = trim((string) ($data['round_name'] ?? ''));

        return $this->rounds->ensure(
            $stageId,
            $number,
            $isTable ? RoundType::Matchday : RoundType::KnockoutRound,
            $name !== '' ? $name : null,
        )->id;
    }

    private function score(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > 99) {
            throw IngestionEntityException::invalid("некорректное значение {$key}", [$key => $value]);
        }

        return $value;
    }
}
