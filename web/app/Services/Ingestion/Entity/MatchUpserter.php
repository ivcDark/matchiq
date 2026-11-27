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
use App\Models\Stage;
use App\Repositories\ExternalIdRepository;
use App\Repositories\MatchOddRepository;
use App\Repositories\MatchRepository;
use App\Repositories\RoundRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\StageRepository;
use App\Repositories\StageTeamRepository;
use App\Services\Ingestion\Exceptions\IngestionEntityException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Матч. Из /Games/list (`data.api_id`): ключ — числовой id матча SStats (`sstats_api`), сезон и команды — по их числовым id
 * (иначе по Ls-id). Этапа /Games/list не знает: он берётся из `stage_external_id` (/Ls/List у многоэтапных сезонов),
 * иначе у уже известного матча, иначе единственная стадия сезона. Счёт ET и серии пенальти /Games/list не отдаёт —
 * без ключей `*_et`/`*_pens` сохранённые значения не трогаются. Коэффициенты (`odds`) синхронизируются в `match_odds`.
 */
class MatchUpserter implements EntityUpserter
{
    private const SCORE_KEYS = [
        'home' => 'home_goals', 'away' => 'away_goals', 'home_ht' => 'home_goals_ht', 'away_ht' => 'away_goals_ht',
        'home_et' => 'home_goals_et', 'away_et' => 'away_goals_et', 'home_pens' => 'home_pens', 'away_pens' => 'away_pens',
    ];

    public function __construct(
        private readonly MatchRepository $matches,
        private readonly RoundRepository $rounds,
        private readonly SeasonRepository $seasons,
        private readonly StageRepository $stages,
        private readonly StageTeamRepository $stageTeams,
        private readonly ExternalIdRepository $externalIds,
        private readonly SstatsApiIds $apiIds,
        private readonly MatchOddRepository $odds,
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
            $kickoff = Carbon::parse((string) ($data['kickoff_at'] ?? ''))->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw IngestionEntityException::invalid('у матча некорректный kickoff_at', ['kickoff_at' => $data['kickoff_at'] ?? null]);
        }
        if (trim((string) ($data['api_id'] ?? '')) !== '') {
            return $this->upsertFromApi($entity, $context, $status, $period, $kickoff);
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
            $this->resolveRoundId($stage->id, $stage->type->hasStandings(), $data, $context),
            $this->resolveGroupId($stage->id, $homeId, $awayId),
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

    private function upsertFromApi(ResultEntity $entity, UpsertContext $context, MatchStatus $status, ?MatchPeriod $period, Carbon $kickoff): UpsertResult
    {
        $data = $entity->data;

        $season = $this->seasons->find($this->apiRef($data, 'season', ExternalEntityType::Season, $context))
            ?? throw IngestionEntityException::missingReference(ExternalEntityType::Season->value, (string) ($data['season_api_id'] ?? ''));
        $homeId = $this->apiRef($data, 'home_team', ExternalEntityType::Team, $context);
        $awayId = $this->apiRef($data, 'away_team', ExternalEntityType::Team, $context);
        if ($homeId === $awayId) {
            throw IngestionEntityException::invalid('хозяева и гости — одна команда', ['team_id' => $homeId]);
        }

        $flashId = trim((string) ($data['flash_id'] ?? ''));
        $existing = $this->resolveExistingFromApi($entity, $context, $flashId, $season->id, $homeId, $awayId, $kickoff);
        $stage = $this->resolveStage($data, $context, $season->id, $existing);

        $score = [];
        foreach (self::SCORE_KEYS as $key => $column) {
            $score[$key] = array_key_exists($column, $data) ? $this->score($data, $column) : $existing?->{$column};
        }

        [$match, $outcome] = $this->matches->upsert(
            $existing,
            $season->competition_id,
            $season->id,
            $stage->id,
            $this->resolveRoundId($stage->id, $stage->type->hasStandings(), $data, $context) ?? $existing?->round_id,
            $this->resolveGroupId($stage->id, $homeId, $awayId),
            $homeId,
            $awayId,
            $kickoff,
            $status,
            $period,
            $score,
        );

        $this->apiIds->track($this->type(), $entity->externalId, $match->id, $context->runId);
        $children = [];
        $refs = $this->apiIds->linkRefs($context->providerId, $this->type(), $match->id, $flashId, $data['refs'] ?? null, $context->runId);
        if ($refs !== []) {
            $children['external_ref'] = $refs;
        }
        $odds = $this->oddsOf($data);
        if ($odds !== []) {
            $children['match_odd'] = $this->odds->sync($match->id, $this->apiIds->providerId(), $odds);
        }

        return new UpsertResult($outcome, $match->id, $children);
    }

    /**
     * Сезон или команда матча: по числовому id SStats (`{prefix}_api_id`), иначе по Ls-id (`{prefix}_external_id`) —
     * например, сезон, выбранный по лиге и году, пока sync_catalog не привязал его uid.
     */
    private function apiRef(array $data, string $prefix, ExternalEntityType $type, UpsertContext $context): int
    {
        $apiId = trim((string) ($data[$prefix.'_api_id'] ?? ''));
        $externalId = trim((string) ($data[$prefix.'_external_id'] ?? ''));
        if ($apiId !== '' && ($id = $this->apiIds->find($type, $apiId)) !== null) {
            return $id;
        }
        if ($apiId !== '' && $externalId === '') {
            return $this->apiIds->require($type, $apiId);
        }

        return $this->externalIds->requireEntityId($context->providerId, $type, $externalId);
    }

    /** По числовому id; затем по flash-id (матчи, загруженные из Ls); затем та же пара в ±1 день без числового id. */
    private function resolveExistingFromApi(ResultEntity $entity, UpsertContext $context, string $flashId, int $seasonId, int $homeId, int $awayId, Carbon $kickoff): ?FootballMatch
    {
        $id = $this->apiIds->find($this->type(), $entity->externalId)
            ?? ($flashId !== '' ? $this->externalIds->findEntityId($context->providerId, $this->type(), $flashId) : null);
        if ($id !== null) {
            return $this->matches->find($id);
        }

        return $this->matches->findByTeamsAround($seasonId, $homeId, $awayId, $kickoff)
            ->first(fn (FootballMatch $m) => ! $this->externalIds->isLinked($this->apiIds->providerId(), $this->type(), $m->id));
    }

    /** @throws IngestionEntityException */
    private function resolveStage(array $data, UpsertContext $context, int $seasonId, ?FootballMatch $existing): Stage
    {
        $externalId = (string) ($data['stage_external_id'] ?? '');
        if ($externalId !== '') {
            $stage = $this->stages->find($this->externalIds->requireEntityId($context->providerId, ExternalEntityType::Stage, $externalId));
            if ($stage === null || $stage->season_id !== $seasonId) {
                throw IngestionEntityException::conflict('стадия не принадлежит сезону матча', ['stage_external_id' => $externalId]);
            }

            return $stage;
        }

        if ($existing !== null && $existing->season_id === $seasonId && ($stage = $this->stages->find($existing->stage_id)) !== null) {
            return $stage;
        }

        return $this->stages->soleInSeason($seasonId) ?? throw IngestionEntityException::missingReference(
            ExternalEntityType::Stage->value,
            $this->stages->countInSeason($seasonId) === 0 ? 'в сезоне нет стадий (сначала sync_structure)' : 'этап матча не известен (несколько стадий, нет данных /Ls/List)',
        );
    }

    /** @return list<array{market_id: int, market_name: ?string, outcome: string, value: float}> */
    private function oddsOf(array $data): array
    {
        $odds = [];
        foreach ((array) ($data['odds'] ?? []) as $row) {
            $marketId = $row['market_id'] ?? null;
            $outcome = trim((string) ($row['outcome'] ?? ''));
            $value = $row['value'] ?? null;
            if (! is_int($marketId) || $marketId < 1 || $outcome === '' || ! is_numeric($value) || $value <= 1 || $value >= 100000) {
                continue;
            }
            $odds[] = [
                'market_id' => $marketId,
                'market_name' => trim((string) ($row['market_name'] ?? '')) ?: null,
                'outcome' => mb_substr($outcome, 0, 64),
                'value' => round((float) $value, 3),
            ];
        }

        return $odds;
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

    /** Группа матча — общая группа обеих команд в стадии; у стадий без групп и при расхождении — null. */
    private function resolveGroupId(int $stageId, int $homeId, int $awayId): ?int
    {
        $home = $this->stageTeams->groupIdOf($stageId, $homeId);

        return $home !== null && $home === $this->stageTeams->groupIdOf($stageId, $awayId) ? $home : null;
    }

    private function resolveRoundId(int $stageId, bool $isTable, array $data, UpsertContext $context): ?int
    {
        $externalId = (string) ($data['round_external_id'] ?? '');
        if ($externalId !== '') {
            $linked = $this->externalIds->findEntityId($context->providerId, ExternalEntityType::Round, $externalId);
            $round = $linked === null ? null : $this->rounds->find($linked);
            if ($round !== null && $round->stage_id === $stageId) {
                return $round->id;
            }
        }

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
