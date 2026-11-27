<?php

namespace App\Services\Predictions;

use App\Domains\Predictions\LeagueBaseline;
use App\Domains\Predictions\TeamProfile;
use App\Enums\MatchStatus;
use App\Enums\StatsPeriod;
use App\Models\FootballMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Профили команд и база лиги для эвристики: по сыгранным матчам до `kickoff`. */
class BuildTeamProfile
{
    public const TEAM_GAMES = 12;

    private const LEAGUE_GAMES = 200;

    private const MIN_LEAGUE_GAMES = 20;

    public function forTeam(int $teamId, CarbonInterface $before): TeamProfile
    {
        $matches = FootballMatch::query()
            ->with('teamStats')
            ->where('status', MatchStatus::Finished)
            ->whereNotNull('home_goals')
            ->whereNotNull('away_goals')
            ->where('kickoff_at', '<', $before)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at')
            ->limit(self::TEAM_GAMES)
            ->get();

        if ($matches->isEmpty()) {
            return new TeamProfile;
        }

        $gf = $ga = 0;
        $statGames = 0;
        $corners = $yellows = $reds = 0;

        foreach ($matches as $match) {
            $home = $match->home_team_id === $teamId;
            $gf += $home ? $match->home_goals : $match->away_goals;
            $ga += $home ? $match->away_goals : $match->home_goals;

            $totals = $this->statTotals($match);
            if ($totals !== null) {
                $statGames++;
                $corners += $totals['corners'];
                $yellows += $totals['yellows'];
                $reds += $totals['reds'];
            }
        }

        $n = $matches->count();

        return new TeamProfile(
            games: $n,
            goalsFor: $gf / $n,
            goalsAgainst: $ga / $n,
            statGames: $statGames,
            corners: $statGames ? $corners / $statGames : 0.0,
            yellows: $statGames ? $yellows / $statGames : 0.0,
            reds: $statGames ? $reds / $statGames : 0.0,
        );
    }

    public function leagueBaseline(FootballMatch $match): LeagueBaseline
    {
        /** @var Collection<int, FootballMatch> $games */
        $games = FootballMatch::query()
            ->where('competition_id', $match->competition_id)
            ->where('status', MatchStatus::Finished)
            ->whereNotNull('home_goals')
            ->whereNotNull('away_goals')
            ->where('kickoff_at', '<', $match->kickoff_at)
            ->orderByDesc('kickoff_at')
            ->limit(self::LEAGUE_GAMES)
            ->get(['home_goals', 'away_goals']);

        if ($games->count() < self::MIN_LEAGUE_GAMES) {
            return new LeagueBaseline;
        }

        return new LeagueBaseline(
            homeGoals: (float) $games->avg('home_goals'),
            awayGoals: (float) $games->avg('away_goals'),
        );
    }

    /** @return array{corners: int, yellows: int, reds: int}|null null, если статистики матча по обеим командам нет */
    private function statTotals(FootballMatch $match): ?array
    {
        $rows = $match->teamStats->filter(fn ($s) => $s->period === StatsPeriod::FullTime);
        if ($rows->count() < 2) {
            return null;
        }

        foreach (['corners', 'yellow_cards', 'red_cards'] as $field) {
            if ($rows->contains(fn ($s) => $s->{$field} === null)) {
                return null;
            }
        }

        return [
            'corners' => (int) $rows->sum('corners'),
            'yellows' => (int) $rows->sum('yellow_cards'),
            'reds' => (int) $rows->sum('red_cards'),
        ];
    }
}
