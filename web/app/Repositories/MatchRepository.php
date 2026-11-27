<?php

namespace App\Repositories;

use App\Enums\MatchPeriod;
use App\Enums\MatchStatus;
use App\Enums\UpsertOutcome;
use App\Models\FootballMatch;
use App\Repositories\Concerns\UpsertsModels;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MatchRepository
{
    use UpsertsModels;

    public function find(int $id): ?FootballMatch
    {
        return FootballMatch::find($id);
    }

    /**
     * Матчи сезона для загрузки деталей: завершённые, свежие первыми.
     *
     * @param  bool  $missingOnly  только без загруженных деталей (нет строк match_teams)
     * @return Collection<int, FootballMatch>
     */
    public function selectFinishedForDetails(int $seasonId, ?int $stageId, ?string $from, ?string $to, bool $missingOnly, int $limit): Collection
    {
        return FootballMatch::where('season_id', $seasonId)
            ->where('status', MatchStatus::Finished)
            ->when($stageId !== null, fn ($q) => $q->where('stage_id', $stageId))
            ->when($from !== null, fn ($q) => $q->where('kickoff_at', '>=', $from.' 00:00:00'))
            ->when($to !== null, fn ($q) => $q->where('kickoff_at', '<=', $to.' 23:59:59'))
            ->when($missingOnly, fn ($q) => $q->whereDoesntHave('matchTeams'))
            ->orderByDesc('kickoff_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, FootballMatch>
     */
    public function findMany(array $ids): Collection
    {
        return FootballMatch::whereIn('id', $ids)->get();
    }

    /**
     * Кандидаты для сопоставления нового external_id: та же пара в сезоне в окне ±1 день от начала.
     *
     * @return Collection<int, FootballMatch>
     */
    public function findByTeamsAround(int $seasonId, int $homeTeamId, int $awayTeamId, CarbonInterface $kickoff): Collection
    {
        return FootballMatch::where('season_id', $seasonId)
            ->where('home_team_id', $homeTeamId)
            ->where('away_team_id', $awayTeamId)
            ->whereBetween('kickoff_at', [$kickoff->copy()->subDay(), $kickoff->copy()->addDay()])
            ->get();
    }

    /**
     * Расписание и счёт из источника. Поля, которых источник не отдаёт (площадка, судья, погода, пары плей-офф), не трогаются.
     *
     * @param  array{home: ?int, away: ?int, home_ht: ?int, away_ht: ?int, home_et: ?int, away_et: ?int, home_pens: ?int, away_pens: ?int}  $score
     * @return array{0: FootballMatch, 1: UpsertOutcome}
     */
    public function upsert(
        ?FootballMatch $existing,
        int $competitionId,
        int $seasonId,
        int $stageId,
        ?int $roundId,
        int $homeTeamId,
        int $awayTeamId,
        CarbonInterface $kickoff,
        MatchStatus $status,
        ?MatchPeriod $period,
        array $score,
    ): array {
        return $this->persist(new FootballMatch, $existing, [
            'competition_id' => $competitionId,
            'season_id' => $seasonId,
            'stage_id' => $stageId,
            'round_id' => $roundId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'kickoff_at' => $kickoff,
            'status' => $status,
            'period' => $status === MatchStatus::Live ? $period : null,
            'home_goals' => $score['home'],
            'away_goals' => $score['away'],
            'home_goals_ht' => $score['home_ht'],
            'away_goals_ht' => $score['away_ht'],
            'home_goals_et' => $score['home_et'],
            'away_goals_et' => $score['away_et'],
            'home_pens' => $score['home_pens'],
            'away_pens' => $score['away_pens'],
        ]);
    }
}
