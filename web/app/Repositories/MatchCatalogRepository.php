<?php

namespace App\Repositories;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchPlayerStat;
use App\Models\MatchPreview;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Чтение матчей для главной и страницы матча (витрина, без записи). */
class MatchCatalogRepository
{
    /**
     * Матчи лиг в окне по времени начала.
     *
     * @param  list<int>  $competitionIds
     * @return Collection<int, FootballMatch>
     */
    public function listInWindow(array $competitionIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($competitionIds === []) {
            return new Collection;
        }

        return FootballMatch::query()
            ->with([
                'homeTeam', 'awayTeam', 'competition',
                'currentPrediction.outcomes.marketType.outcomes',
            ])
            ->whereIn('competition_id', $competitionIds)
            ->where('kickoff_at', '>=', $from)
            ->where('kickoff_at', '<', $to)
            ->orderBy('kickoff_at')
            ->orderBy('id')
            ->get();
    }

    public function findForShow(int $id): ?FootballMatch
    {
        return FootballMatch::query()
            ->with([
                'homeTeam', 'awayTeam', 'venue', 'referee', 'round', 'season',
                'competition.country', 'currentPrediction.scorelines',
                'events.player', 'events.relatedPlayer', 'teamStats',
            ])
            ->find($id);
    }

    /**
     * Завершённые матчи со счётом, где играет любая из команд (все турниры).
     *
     * @param  list<int>  $teamIds
     * @return Collection<int, FootballMatch>
     */
    public function finishedForTeams(array $teamIds, ?CarbonInterface $before = null): Collection
    {
        if ($teamIds === []) {
            return new Collection;
        }

        return FootballMatch::query()
            ->with(['homeTeam', 'awayTeam', 'competition'])
            ->where('status', MatchStatus::Finished)
            ->whereNotNull('home_goals')
            ->whereNotNull('away_goals')
            ->when($before !== null, fn ($q) => $q->where('kickoff_at', '<', $before))
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->orderBy('kickoff_at')
            ->get();
    }

    /**
     * Завершённые матчи сезона со счётом — основа таблицы лиги.
     *
     * @return Collection<int, FootballMatch>
     */
    public function finishedInSeason(int $seasonId): Collection
    {
        return FootballMatch::query()
            ->where('season_id', $seasonId)
            ->where('status', MatchStatus::Finished)
            ->whereNotNull('home_goals')
            ->whereNotNull('away_goals')
            ->get(['id', 'home_team_id', 'away_team_id', 'home_goals', 'away_goals', 'kickoff_at']);
    }

    /**
     * Текущий сезон лиги; без отметки is_current — самый поздний по началу.
     */
    public function currentSeason(int $competitionId): ?Season
    {
        return Season::query()
            ->where('competition_id', $competitionId)
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Топ бомбардиров сезона по голам из событий матчей (автоголы не считаются).
     *
     * @return list<array{player: Player, team: Team, goals: int}>
     */
    public function topScorers(int $seasonId, int $limit): array
    {
        $rows = MatchEvent::query()
            ->selectRaw('player_id, team_id, count(*) as goals')
            ->whereIn('type', [MatchEventType::Goal, MatchEventType::PenaltyGoal])
            ->whereNotNull('player_id')
            ->whereNotNull('team_id')
            ->whereHas('match', fn ($q) => $q->where('season_id', $seasonId))
            ->groupBy('player_id', 'team_id')
            ->orderByDesc('goals')
            ->orderBy('player_id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $players = Player::whereIn('id', $rows->pluck('player_id'))->get()->keyBy('id');
        $teams = Team::whereIn('id', $rows->pluck('team_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'player' => $players[$row->player_id],
            'team' => $teams[$row->team_id],
            'goals' => (int) $row->goals,
        ])->all();
    }

    /**
     * Скорострелы за период по всем переданным соревнованиям (кросс-лига): голы на минуту на поле из `match_player_stats`
     * по сыгранным матчам. В рейтинг попадают игроки не меньше `$minMinutes` минут: иначе лидирует один гол за пять минут.
     * Порядок: голы/минуты по убыванию, затем голы, затем id игрока. Игрок в нескольких командах и соревнованиях — одна строка;
     * команда и соревнование — где он провёл больше минут.
     *
     * @param  list<int>  $competitionIds
     * @return list<array{player: Player, team: Team, competition: Competition, goals: int, minutes: int, goals_per_90: float, minutes_per_goal: int}>
     */
    public function fastScorersSince(array $competitionIds, CarbonInterface $since, int $minMinutes, int $limit): array
    {
        if ($competitionIds === []) {
            return [];
        }

        $rows = MatchPlayerStat::query()
            ->join('matches', 'matches.id', '=', 'match_player_stats.match_id')
            ->selectRaw('match_player_stats.player_id, match_player_stats.team_id, matches.competition_id')
            ->selectRaw('sum(match_player_stats.minutes) as minutes, sum(match_player_stats.goals) as goals')
            ->where('matches.status', MatchStatus::Finished)
            ->whereIn('matches.competition_id', $competitionIds)
            ->where('matches.kickoff_at', '>=', $since)
            ->where('match_player_stats.minutes', '>', 0)
            ->groupBy('match_player_stats.player_id', 'match_player_stats.team_id', 'matches.competition_id')
            ->get();

        $byPlayer = [];
        foreach ($rows as $row) {
            $minutes = (int) $row->minutes;
            $acc = &$byPlayer[$row->player_id];
            $acc ??= ['goals' => 0, 'minutes' => 0, 'best' => 0, 'team_id' => $row->team_id, 'competition_id' => $row->competition_id];
            $acc['goals'] += (int) $row->goals;
            $acc['minutes'] += $minutes;
            if ($minutes > $acc['best']) {
                $acc['best'] = $minutes;
                $acc['team_id'] = $row->team_id;
                $acc['competition_id'] = $row->competition_id;
            }
            unset($acc);
        }

        $top = array_filter($byPlayer, fn (array $r) => $r['goals'] > 0 && $r['minutes'] >= $minMinutes);
        uksort($top, fn (int $a, int $b) => [$top[$b]['goals'] / $top[$b]['minutes'], $top[$b]['goals'], $a]
            <=> [$top[$a]['goals'] / $top[$a]['minutes'], $top[$a]['goals'], $b]);
        $top = array_slice($top, 0, $limit, true);
        if ($top === []) {
            return [];
        }

        $players = Player::whereIn('id', array_keys($top))->get()->keyBy('id');
        $teams = Team::whereIn('id', array_column($top, 'team_id'))->get()->keyBy('id');
        $competitions = Competition::whereIn('id', array_column($top, 'competition_id'))->get()->keyBy('id');

        $out = [];
        foreach ($top as $playerId => $row) {
            $out[] = [
                'player' => $players[$playerId],
                'team' => $teams[$row['team_id']],
                'competition' => $competitions[$row['competition_id']],
                'goals' => $row['goals'],
                'minutes' => $row['minutes'],
                'goals_per_90' => round($row['goals'] * 90 / $row['minutes'], 2),
                'minutes_per_goal' => (int) round($row['minutes'] / $row['goals']),
            ];
        }

        return $out;
    }

    public function previewFor(int $matchId, string $locale): ?MatchPreview
    {
        return MatchPreview::query()
            ->where('match_id', $matchId)
            ->where('locale', $locale)
            ->latest('generated_at')
            ->first();
    }

    /**
     * Последнее превью каждого матча (одним запросом) для главной.
     *
     * @param  list<int>  $matchIds
     * @return Collection<int, MatchPreview> ключ — match_id
     */
    public function previewsForMatches(array $matchIds, string $locale): Collection
    {
        if ($matchIds === []) {
            return new Collection;
        }

        return MatchPreview::query()
            ->whereIn('match_id', $matchIds)
            ->where('locale', $locale)
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get()
            ->keyBy('match_id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Team>
     */
    public function teamsByIds(array $ids): Collection
    {
        return Team::whereIn('id', $ids)->get()->keyBy('id');
    }
}
