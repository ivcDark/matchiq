<?php

namespace App\Http\Responses\Matches;

use App\Domains\Matches\TeamForm;
use App\Enums\RoundType;
use App\Models\FootballMatch;

final class MatchHeaderPayload
{
    /**
     * Шапка страницы матча — только то, что есть в БД. Ссылки на клуб и турнир — id команды и лиги.
     *
     * @return array<string, mixed>
     */
    public static function make(FootballMatch $match, TeamForm $form, bool $detailsLoaded): array
    {
        $locale = config('matchiq.ui_locale');
        $league = LeaguePayload::make($match->competition);

        return [
            'id' => $match->id,
            'league_id' => $league['id'],
            'league' => LeaguePayload::label($match->competition),
            'league_short' => $league['short'],
            'league_country' => $league['country'],
            'tournament_id' => $league['tournament_id'],
            'home' => $match->homeTeam->localized('name', $locale),
            'away' => $match->awayTeam->localized('name', $locale),
            'home_club_id' => $match->home_team_id,
            'away_club_id' => $match->away_team_id,
            'kickoff_at' => $match->kickoff_at->toIso8601String(),
            'status' => $match->status->value,
            'minute' => $match->minute,
            'score_home' => $match->home_goals,
            'score_away' => $match->away_goals,
            'ht_home' => $match->home_goals_ht,
            'ht_away' => $match->away_goals_ht,
            'form_home' => MatchRowPayload::letters($form, $match->home_team_id, $match),
            'form_away' => MatchRowPayload::letters($form, $match->away_team_id, $match),
            'venue' => self::venue($match),
            'referee' => $match->referee?->localized('name', $locale),
            'round' => self::round($match),
            'weather' => self::weather($match),
            'details_loaded' => $detailsLoaded,
        ];
    }

    private static function venue(FootballMatch $match): ?string
    {
        $venue = $match->venue;
        if ($venue === null) {
            return null;
        }

        return implode(', ', array_filter([$venue->name, $venue->localized('city')]));
    }

    private static function round(FootballMatch $match): ?string
    {
        $round = $match->round;
        if ($round === null) {
            return null;
        }

        return $round->type === RoundType::Matchday ? $round->number.' тур' : $round->name;
    }

    /** @return array{temp_c: ?float, wind_ms: ?float, condition: ?string}|null */
    private static function weather(FootballMatch $match): ?array
    {
        if ($match->temperature_c === null && $match->wind_speed_ms === null && $match->weather_condition === null) {
            return null;
        }

        return [
            'temp_c' => $match->temperature_c === null ? null : (float) $match->temperature_c,
            'wind_ms' => $match->wind_speed_ms === null ? null : (float) $match->wind_speed_ms,
            'condition' => $match->weather_condition,
        ];
    }
}
