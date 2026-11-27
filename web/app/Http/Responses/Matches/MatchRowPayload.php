<?php

namespace App\Http\Responses\Matches;

use App\Domains\Matches\TeamForm;
use App\Enums\MatchStatus;
use App\Models\FootballMatch;

final class MatchRowPayload
{
    /**
     * Строка списка матчей главной. Вероятности — только из текущего прогноза модели, иначе null;
     * топ-рынок появится вместе с рынками модели, пока всегда null.
     *
     * @return array<string, mixed>
     */
    public static function make(FootballMatch $match, TeamForm $form): array
    {
        $locale = config('matchiq.ui_locale');
        $prediction = $match->currentPrediction;
        $pct = static fn ($p): ?int => $p === null ? null : (int) round(((float) $p) * 100);
        $showProbs = $prediction !== null && $match->status !== MatchStatus::Finished;

        return [
            'id' => $match->id,
            'league_id' => $match->competition->slug,
            'home' => $match->homeTeam->localized('name', $locale),
            'away' => $match->awayTeam->localized('name', $locale),
            'kickoff_at' => $match->kickoff_at->toIso8601String(),
            'status' => $match->status->value,
            'minute' => $match->minute,
            'score_home' => $match->home_goals,
            'score_away' => $match->away_goals,
            'form_home' => self::letters($form, $match->home_team_id, $match),
            'form_away' => self::letters($form, $match->away_team_id, $match),
            'prob_home' => $showProbs ? $pct($prediction->p_home) : null,
            'prob_draw' => $showProbs ? $pct($prediction->p_draw) : null,
            'prob_away' => $showProbs ? $pct($prediction->p_away) : null,
            'top_market' => null,
        ];
    }

    /** @return list<string> */
    public static function letters(TeamForm $form, int $teamId, FootballMatch $match): array
    {
        return array_map(
            static fn ($result) => $result->value,
            $form->results($teamId, $match->kickoff_at),
        );
    }
}
