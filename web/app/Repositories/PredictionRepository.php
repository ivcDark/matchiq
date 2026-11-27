<?php

namespace App\Repositories;

use App\Models\FootballMatch;
use App\Models\MatchPrediction;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;

/** Запись прогнозов: каждый прогон — новый неизменяемый снимок, актуальный помечается `is_current`. */
class PredictionRepository
{
    public function modelVersion(string $code, string $name, string $version): ModelVersion
    {
        return ModelVersion::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'version' => $version, 'is_active' => true, 'trained_at' => now()],
        );
    }

    public function hasCurrent(int $matchId): bool
    {
        return MatchPrediction::query()->where('match_id', $matchId)->where('is_current', true)->exists();
    }

    /**
     * @param  array{expected_home: float, expected_away: float, p_home: float, p_draw: float, p_away: float, outcomes: list<array{market: string, outcome: string, line: float|null, probability: float}>, scorelines: list<array{home: int, away: int, probability: float}>}  $result
     * @param  array<string, int>  $marketIds  код рынка → id
     */
    public function saveSnapshot(FootballMatch $match, ModelVersion $version, array $result, array $marketIds): MatchPrediction
    {
        return DB::transaction(function () use ($match, $version, $result, $marketIds) {
            MatchPrediction::query()->where('match_id', $match->id)->where('is_current', true)->update(['is_current' => false]);

            $prediction = MatchPrediction::create([
                'match_id' => $match->id,
                'model_version_id' => $version->id,
                'is_current' => true,
                'expected_home_goals' => $result['expected_home'],
                'expected_away_goals' => $result['expected_away'],
                'p_home' => $result['p_home'],
                'p_draw' => $result['p_draw'],
                'p_away' => $result['p_away'],
                'generated_at' => now(),
            ]);

            $prediction->outcomes()->createMany(array_map(fn (array $row) => [
                'market_type_id' => $marketIds[$row['market']],
                'outcome_key' => $row['outcome'],
                'line' => $row['line'],
                'probability' => $row['probability'],
            ], $result['outcomes']));

            $prediction->scorelines()->createMany(array_map(fn (array $row) => [
                'home_goals' => $row['home'],
                'away_goals' => $row['away'],
                'probability' => $row['probability'],
            ], $result['scorelines']));

            return $prediction;
        });
    }
}
