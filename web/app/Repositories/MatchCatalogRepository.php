<?php

namespace App\Repositories;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
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
            ->with(['homeTeam', 'awayTeam', 'competition', 'currentPrediction'])
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
     * Топ голов игроков за период по всем переданным соревнованиям (кросс-лига): голы из событий матчей, автоголы не считаются.
     * Игрок с голами в нескольких соревнованиях — одна строка; команда и соревнование — где он забил больше.
     *
     * @param  list<int>  $competitionIds
     * @return list<array{player: Player, team: Team, competition: Competition, goals: int}>
     */
    public function topScorersSince(array $competitionIds, CarbonInterface $since, int $limit): array
    {
        if ($competitionIds === []) {
            return [];
        }

        $rows = MatchEvent::query()
            ->join('matches', 'matches.id', '=', 'match_events.match_id')
            ->selectRaw('match_events.player_id, match_events.team_id, matches.competition_id, count(*) as goals')
            ->whereIn('match_events.type', [MatchEventType::Goal, MatchEventType::PenaltyGoal])
            ->whereNotNull('match_events.player_id')
            ->whereNotNull('match_events.team_id')
            ->whereIn('matches.competition_id', $competitionIds)
            ->where('matches.kickoff_at', '>=', $since)
            ->groupBy('match_events.player_id', 'match_events.team_id', 'matches.competition_id')
            ->get();

        $byPlayer = [];
        foreach ($rows as $row) {
            $goals = (int) $row->goals;
            $acc = &$byPlayer[$row->player_id];
            $acc ??= ['total' => 0, 'best' => 0, 'team_id' => $row->team_id, 'competition_id' => $row->competition_id];
            $acc['total'] += $goals;
            if ($goals > $acc['best']) {
                $acc['best'] = $goals;
                $acc['team_id'] = $row->team_id;
                $acc['competition_id'] = $row->competition_id;
            }
            unset($acc);
        }

        $playerIds = array_keys($byPlayer);
        usort($playerIds, fn (int $a, int $b) => [$byPlayer[$b]['total'], $a] <=> [$byPlayer[$a]['total'], $b]);
        $top = [];
        foreach (array_slice($playerIds, 0, $limit) as $playerId) {
            $top[$playerId] = $byPlayer[$playerId];
        }
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
                'goals' => $row['total'],
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
     * @param  list<int>  $ids
     * @return Collection<int, Team>
     */
    public function teamsByIds(array $ids): Collection
    {
        return Team::whereIn('id', $ids)->get()->keyBy('id');
    }
}
