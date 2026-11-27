<?php

namespace App\Http\Responses\Matches;

use App\Models\MatchPrediction;

final class ForecastPayload
{
    private const TOP_SCORELINES = 5;

    /**
     * 1X2 и точный счёт текущего прогноза модели; null, если прогноза нет — фейковых цифр не показываем.
     *
     * @return array<string, mixed>|null
     */
    public static function make(?MatchPrediction $prediction): ?array
    {
        if ($prediction === null || $prediction->p_home === null || $prediction->p_draw === null || $prediction->p_away === null) {
            return null;
        }

        $scorelines = $prediction->scorelines->sortByDesc(fn ($s) => (float) $s->probability)->values();
        $top = $scorelines->first();

        $scoreHome = $top?->home_goals ?? ($prediction->expected_home_goals === null ? null : (int) round((float) $prediction->expected_home_goals));
        $scoreAway = $top?->away_goals ?? ($prediction->expected_away_goals === null ? null : (int) round((float) $prediction->expected_away_goals));

        return [
            'home_pct' => (int) round((float) $prediction->p_home * 100),
            'draw_pct' => (int) round((float) $prediction->p_draw * 100),
            'away_pct' => (int) round((float) $prediction->p_away * 100),
            'score_home' => $scoreHome,
            'score_away' => $scoreAway,
            'correct_scores' => $scorelines->take(self::TOP_SCORELINES)->map(fn ($s) => [
                'score' => $s->home_goals.'∶'.$s->away_goals,
                'pct' => (int) round((float) $s->probability * 100),
            ])->all(),
        ];
    }
}
