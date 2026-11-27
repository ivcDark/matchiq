<?php

namespace App\Services\Predictions;

use App\Domains\Predictions\HeuristicModel;
use App\Enums\MatchStatus;
use App\Models\FootballMatch;
use App\Models\MatchPrediction;
use App\Repositories\MarketTypeRepository;
use App\Repositories\PredictionRepository;
use Carbon\CarbonInterface;

/** Считает эвристические вероятности (MFP-5) для предстоящих матчей и сохраняет снимок прогноза. */
class GenerateMatchPredictions
{
    public const MODEL_CODE = 'heuristic_poisson_v1';

    /** @var array<string, int>|null */
    private ?array $marketIds = null;

    public function __construct(
        private readonly HeuristicModel $model,
        private readonly BuildTeamProfile $profiles,
        private readonly MarketTypeRepository $markets,
        private readonly PredictionRepository $predictions,
    ) {}

    /**
     * Матчи со стартом в окне [now, now + $days], без текущего прогноза (или все при $force).
     *
     * @return int сколько прогнозов создано
     */
    public function handleUpcoming(int $days, bool $force = false, ?CarbonInterface $now = null): int
    {
        $now ??= now();

        $matches = FootballMatch::query()
            ->with('competition')
            ->where('status', MatchStatus::Scheduled)
            ->where('kickoff_at', '>', $now)
            ->where('kickoff_at', '<=', $now->copy()->addDays($days))
            ->orderBy('kickoff_at')
            ->get();

        $created = 0;
        foreach ($matches as $match) {
            if ($this->handle($match, $force) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /** Null, если матч уже начался/сыгран или прогноз есть и $force не задан. */
    public function handle(FootballMatch $match, bool $force = false): ?MatchPrediction
    {
        if ($match->status !== MatchStatus::Scheduled || $match->kickoff_at->lte(now())) {
            return null;
        }
        if (! $force && $this->predictions->hasCurrent($match->id)) {
            return null;
        }

        $result = $this->model->predict(
            $this->profiles->forTeam($match->home_team_id, $match->kickoff_at),
            $this->profiles->forTeam($match->away_team_id, $match->kickoff_at),
            $this->profiles->leagueBaseline($match),
        );

        $this->marketIds ??= array_map(fn ($type) => $type->id, $this->markets->syncCatalog());
        $version = $this->predictions->modelVersion(self::MODEL_CODE, 'Poisson heuristic', '1');

        return $this->predictions->saveSnapshot(
            $match,
            $version,
            $result,
            $this->marketIds,
        );
    }
}
