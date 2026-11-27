<?php

namespace App\Services\Picks;

use App\Domains\Predictions\MatchFacts;
use App\Domains\Predictions\OutcomeEvaluator;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\PickStatus;
use App\Enums\StatsPeriod;
use App\Models\FootballMatch;
use App\Models\Pick;
use App\Models\PredictionOutcome;
use App\Support\RatingRules;
use Illuminate\Support\Facades\DB;

/**
 * Резолв после матча: проставляет `prediction_outcomes.is_hit` и закрывает picks (won/lost + очки).
 * Идемпотентен: трогает только ещё не разрешённое. Отменённый матч — picks void без очков.
 */
class ResolveMatchPicks
{
    /** @return array{resolved: int, pending: int, void: int} */
    public function handle(FootballMatch $match): array
    {
        if (in_array($match->status, [MatchStatus::Cancelled, MatchStatus::Abandoned], true)) {
            return ['resolved' => 0, 'pending' => 0, 'void' => $this->voidPending($match)];
        }

        if ($match->status !== MatchStatus::Finished || $match->home_goals === null || $match->away_goals === null) {
            return ['resolved' => 0, 'pending' => 0, 'void' => 0];
        }

        $facts = $this->facts($match);

        return DB::transaction(function () use ($match, $facts) {
            $this->markOutcomes($match, $facts);

            $resolved = $pending = 0;
            $picks = Pick::query()->active()->pending()->where('match_id', $match->id)->with('predictionOutcome')->get();

            foreach ($picks as $pick) {
                $hit = $pick->predictionOutcome?->is_hit;
                if ($hit === null) {
                    $pending++;

                    continue;
                }

                $pick->update([
                    'status' => $hit ? PickStatus::Won : PickStatus::Lost,
                    'points' => $hit ? RatingRules::points((int) round((float) $pick->probability_at_pick * 100)) : 0,
                    'resolved_at' => now(),
                ]);
                $resolved++;
            }

            return ['resolved' => $resolved, 'pending' => $pending, 'void' => 0];
        });
    }

    private function markOutcomes(FootballMatch $match, MatchFacts $facts): void
    {
        $outcomes = PredictionOutcome::query()
            ->whereNull('is_hit')
            ->whereHas('matchPrediction', fn ($q) => $q->where('match_id', $match->id))
            ->with('marketType')
            ->get();

        foreach ($outcomes as $outcome) {
            $hit = OutcomeEvaluator::isHit(
                $outcome->marketType->code,
                $outcome->outcome_key,
                $outcome->line === null ? null : (float) $outcome->line,
                $facts,
            );
            if ($hit !== null) {
                $outcome->update(['is_hit' => $hit, 'resolved_at' => now()]);
            }
        }
    }

    private function voidPending(FootballMatch $match): int
    {
        return Pick::query()->active()->pending()->where('match_id', $match->id)->update([
            'status' => PickStatus::Void,
            'points' => 0,
            'resolved_at' => now(),
        ]);
    }

    private function facts(FootballMatch $match): MatchFacts
    {
        $match->loadMissing(['teamStats', 'events']);

        $stats = $match->teamStats->filter(fn ($s) => $s->period === StatsPeriod::FullTime);
        $sum = function (string $field) use ($stats): ?int {
            if ($stats->count() < 2 || $stats->contains(fn ($s) => $s->{$field} === null)) {
                return null;
            }

            return (int) $stats->sum($field);
        };

        $yellows = $sum('yellow_cards');
        $reds = $sum('red_cards');

        if ($match->events->isNotEmpty()) {
            $yellows ??= $match->events->filter(fn ($e) => in_array($e->type, [MatchEventType::Yellow, MatchEventType::SecondYellow], true))->count();
            $reds ??= $match->events->filter(fn ($e) => in_array($e->type, [MatchEventType::Red, MatchEventType::SecondYellow], true))->count();
        }

        return new MatchFacts($match->home_goals, $match->away_goals, $sum('corners'), $yellows, $reds);
    }
}
