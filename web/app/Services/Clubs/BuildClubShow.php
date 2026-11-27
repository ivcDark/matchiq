<?php

namespace App\Services\Clubs;

use App\Domains\Matches\LeagueTable;
use App\Domains\Tournaments\FinishedMatch;
use App\Domains\Tournaments\RoundLabel;
use App\Domains\Tournaments\SeasonLabel;
use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchEventType;
use App\Enums\MatchResult;
use App\Enums\MatchStatus;
use App\Enums\StageType;
use App\Http\Responses\Clubs\ClubPlayersPayload;
use App\Http\Responses\Clubs\ClubShowResponse;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\StageTeam;
use App\Models\Team;
use App\Repositories\CompetitionCatalogRepository;
use App\Repositories\MatchCatalogRepository;
use App\Repositories\TournamentCatalogRepository;
use Illuminate\Support\Collection;

/**
 * Страница клуба: место в таблице, матчи, голы, карточки и игроки считаются из матчей и событий БД.
 * Турниры клуба — все нетоварищеские соревнования текущего сезона, где у команды есть матчи или заявка на этап:
 * для лиги — место в таблице, для кубка — группа или стадия плей-офф. Удары, владение, точность пасов и рейтинг
 * команды — средние за матч из `match_team_stats` и `match_teams`, минуты — по `match_player_stats`;
 * чего в БД нет — пустые значения.
 */
class BuildClubShow
{
    private const CONTEXTS = ['overall' => null, 'home' => 'home', 'away' => 'away'];

    private const ALL_KEY = 'all';

    private const NEIGHBORS = 5;

    public function __construct(
        private readonly CompetitionCatalogRepository $competitions,
        private readonly MatchCatalogRepository $matches,
        private readonly TournamentCatalogRepository $catalog,
        private readonly BuildClubCupStatus $cups,
    ) {}

    public function handle(int $teamId): ?ClubShowResponse
    {
        $team = $this->catalog->findTeam($teamId);
        if ($team === null) {
            return null;
        }

        $locale = config('matchiq.ui_locale');
        $teamMatches = $this->catalog->matchesForTeam($team->id);
        $stageTeams = $this->catalog->stageTeamsForTeam($team->id);

        $candidates = $teamMatches->pluck('competition_id')
            ->merge($stageTeams->map(fn ($st) => $st->stage?->season?->competition_id))
            ->filter()->unique()->values()->all();

        $visible = $this->competitions->visibleByIds($candidates)
            ->reject(fn (Competition $c) => $c->type === CompetitionType::Friendly);

        $seasons = [];
        foreach ($visible as $competition) {
            $seasons[$competition->id] = $this->matches->currentSeason($competition->id);
        }

        $scoped = $teamMatches->filter(
            fn (FootballMatch $m) => isset($seasons[$m->competition_id])
                && $seasons[$m->competition_id]?->id === $m->season_id
                && $m->stage?->type !== StageType::Friendly,
        )->values();

        $participation = $stageTeams->filter(
            fn ($st) => $st->stage !== null
                && $st->stage->type !== StageType::Friendly
                && isset($seasons[$st->stage->season?->competition_id])
                && $seasons[$st->stage->season->competition_id]?->id === $st->stage->season_id,
        )->values();

        $present = $scoped->pluck('competition_id')
            ->merge($participation->map(fn ($st) => $st->stage->season->competition_id))
            ->unique()->all();

        $competitions = $visible
            ->filter(fn (Competition $c) => in_array($c->id, $present, true))
            ->sortBy([
                fn (Competition $a, Competition $b) => ($a->type === CompetitionType::League ? 0 : 1) <=> ($b->type === CompetitionType::League ? 0 : 1),
                fn (Competition $a, Competition $b) => ($a->status === CompetitionStatus::Live ? 0 : 1) <=> ($b->status === CompetitionStatus::Live ? 0 : 1),
            ]);

        $primary = $competitions->first();

        $keys = $competitions->mapWithKeys(fn (Competition $c) => [$c->id => $c->slug])->all();
        $tournaments = $this->tournaments($competitions, $locale);
        $finished = $scoped->filter(fn (FootballMatch $m) => FinishedMatch::is($m))->values();

        $opponents = $this->catalog->teamsByIds(
            $scoped->map(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->away_team_id : $m->home_team_id)->unique()->values()->all(),
        );

        $fixtures = $this->fixtures($scoped, $team, $opponents, $keys, $competitions, $locale);

        $primarySeason = $primary === null ? null : $seasons[$primary->id];
        $primaryScoped = $primary === null ? new Collection : $scoped->where('competition_id', $primary->id);

        $blocks = $this->blocks($team, $competitions, $seasons, $scoped, $participation, $fixtures['upcoming'], $locale);
        $standing = collect($blocks)->first(fn (array $b) => $b['kind'] === 'league' && $b['competition_id'] === $primary?->id)['standing']
            ?? $this->emptyStanding($primary, $locale);

        return new ClubShowResponse([
            'club' => $this->club($team, $primary, $primarySeason, $primaryScoped, $locale),
            'standing' => $standing,
            'competitions' => $blocks,
            'tournaments' => $tournaments,
            'last_match' => $fixtures['played'][0] ?? null,
            'next_match' => $fixtures['upcoming'][0] ?? null,
            'calendar' => $fixtures['upcoming'],
            'results' => $fixtures['played'],
            'stats' => $this->stats($team, $finished, $keys, $competitions, $tournaments, $locale),
            'goals' => $this->goals($finished, $team, $keys, $tournaments),
            'players' => $this->players($team, $finished, $keys, $tournaments, $locale),
        ]);
    }

    /**
     * Блоки сводки по турнирам клуба: таблица лиги или статус в кубке.
     *
     * @param  Collection<int, Competition>  $competitions
     * @param  array<int, ?Season>  $seasons
     * @param  Collection<int, FootballMatch>  $scoped
     * @param  Collection<int, StageTeam>  $participation
     * @param  list<array<string, mixed>>  $upcoming
     * @return list<array<string, mixed>>
     */
    private function blocks(Team $team, Collection $competitions, array $seasons, Collection $scoped, Collection $participation, array $upcoming, string $locale): array
    {
        $blocks = [];
        foreach ($competitions as $competition) {
            $name = $competition->localized('short_name', $locale) ?: $competition->localized('name', $locale);
            $inCompetition = $scoped->where('competition_id', $competition->id)->values();

            if ($competition->type === CompetitionType::League) {
                $blocks[] = [
                    'key' => $competition->slug,
                    'kind' => 'league',
                    'competition_id' => $competition->id,
                    'name' => $name,
                    'standing' => $this->standing($team, $competition, $seasons[$competition->id], $inCompetition, $locale),
                ];

                continue;
            }

            $stageTeams = $participation->filter(fn ($st) => $st->stage->season->competition_id === $competition->id)->values();
            $next = collect($upcoming)->first(fn (array $row) => $row['tournament'] === $competition->slug);

            $blocks[] = $this->cups->handle($team, $competition, $inCompetition, $stageTeams, $next, $name, $locale);
        }

        return $blocks;
    }

    /**
     * @param  Collection<int, Competition>  $competitions
     * @return list<array{key: string, name: string}>
     */
    private function tournaments(Collection $competitions, string $locale): array
    {
        $list = $competitions->map(fn (Competition $c) => [
            'key' => $c->slug,
            'name' => $c->localized('short_name', $locale) ?: $c->localized('name', $locale),
        ])->values()->all();

        if (count($list) !== 1) {
            $list[] = ['key' => self::ALL_KEY, 'name' => 'Все матчи'];
        }

        return $list;
    }

    /**
     * @param  Collection<int, FootballMatch>  $primaryScoped
     * @return array<string, mixed>
     */
    private function club(Team $team, ?Competition $primary, ?Season $season, Collection $primaryScoped, string $locale): array
    {
        $rounds = $primaryScoped->map(fn (FootballMatch $m) => $m->round?->number ?? 0);
        $played = $primaryScoped->filter(fn (FootballMatch $m) => FinishedMatch::is($m))
            ->map(fn (FootballMatch $m) => $m->round?->number ?? 0);

        return [
            'id' => $team->id,
            'name' => $team->localized('name', $locale),
            'country' => $team->country?->localized('name', $locale) ?? '',
            'city' => $team->localized('city', $locale) ?? '',
            'tournament_id' => $primary?->id,
            'season' => $season === null ? '' : SeasonLabel::format($season->label),
            'round' => (int) ($played->max() ?? 0),
            'rounds_total' => (int) ($season?->rounds_total ?? $rounds->max() ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyStanding(?Competition $competition, string $locale): array
    {
        $league = $competition?->type === CompetitionType::League ? $competition : null;

        return [
            'tournament_id' => $league?->id,
            'tournament' => $league === null ? '' : ($league->localized('short_name', $locale) ?: $league->localized('name', $locale)),
            'round' => 0,
            'position' => null, 'teams' => 0, 'zone' => null, 'zone_label' => null,
            'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0,
            'form' => [], 'behind_leader' => null, 'neighbors' => [],
        ];
    }

    /**
     * @param  Collection<int, FootballMatch>  $inCompetition
     * @return array<string, mixed>
     */
    private function standing(Team $team, Competition $competition, ?Season $season, Collection $inCompetition, string $locale): array
    {
        $empty = $this->emptyStanding($competition, $locale);
        $empty['round'] = (int) ($inCompetition->filter(fn (FootballMatch $m) => FinishedMatch::is($m))
            ->map(fn (FootballMatch $m) => $m->round?->number ?? 0)->max() ?? 0);

        if ($season === null) {
            return $empty;
        }

        $table = LeagueTable::compute($this->matches->finishedInSeason($season->id));
        $index = null;
        foreach ($table as $i => $row) {
            if ($row['team_id'] === $team->id) {
                $index = $i;
            }
        }
        if ($index === null) {
            return ['teams' => count($table)] + $empty;
        }

        $row = $table[$index];
        $start = max(0, min($index - 2, count($table) - self::NEIGHBORS));
        $window = array_slice($table, $start, self::NEIGHBORS);
        $names = $this->catalog->teamsByIds(array_column($window, 'team_id'));

        return array_merge($empty, [
            'position' => $row['pos'],
            'teams' => count($table),
            'played' => $row['played'], 'wins' => $row['wins'], 'draws' => $row['draws'], 'losses' => $row['losses'],
            'gf' => $row['gf'], 'ga' => $row['ga'], 'gd' => $row['gd'], 'pts' => $row['pts'],
            'form' => $row['form'],
            'behind_leader' => $table[0]['pts'] - $row['pts'],
            'neighbors' => array_map(fn (array $r) => [
                'pos' => $r['pos'],
                'team' => $names[$r['team_id']]->localized('name', $locale),
                'team_id' => $r['team_id'],
                'played' => $r['played'],
                'gd' => $r['gd'],
                'pts' => $r['pts'],
                'zone' => null,
                'is_current' => $r['team_id'] === $team->id,
            ], $window),
        ]);
    }

    /**
     * @param  Collection<int, FootballMatch>  $scoped
     * @param  Collection<int, Team>  $opponents
     * @param  array<int, string>  $keys
     * @param  Collection<int, Competition>  $competitions
     * @return array{played: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    private function fixtures(Collection $scoped, Team $team, Collection $opponents, array $keys, Collection $competitions, string $locale): array
    {
        $row = function (FootballMatch $m) use ($team, $opponents, $keys, $competitions, $locale): array {
            $isHome = $m->home_team_id === $team->id;
            $opponentId = $isHome ? $m->away_team_id : $m->home_team_id;
            $competition = $competitions[$m->competition_id];
            $row = [
                'id' => $m->id,
                'tournament' => $keys[$m->competition_id],
                'tournament_name' => $competition->localized('short_name', $locale) ?: $competition->localized('name', $locale),
                'stage' => RoundLabel::label($m->round, $m->stageGroup, $m->stage),
                'date' => $m->kickoff_at->toDateString(),
                'time' => $m->kickoff_at->format('H:i'),
                'venue' => $isHome ? 'home' : 'away',
                'opponent' => $opponents[$opponentId]->localized('name', $locale),
                'opponent_id' => $opponentId,
                'home' => ($isHome ? $team : $opponents[$opponentId])->localized('name', $locale),
                'away' => ($isHome ? $opponents[$opponentId] : $team)->localized('name', $locale),
            ];

            if (FinishedMatch::is($m)) {
                $gf = $isHome ? $m->home_goals : $m->away_goals;
                $ga = $isHome ? $m->away_goals : $m->home_goals;
                $row += [
                    'home_score' => $m->home_goals,
                    'away_score' => $m->away_goals,
                    'gf' => $gf,
                    'ga' => $ga,
                    'result' => MatchResult::fromGoals($gf, $ga)->value,
                ];
            }

            return $row;
        };

        $played = $scoped->filter(fn (FootballMatch $m) => FinishedMatch::is($m))->sortByDesc(fn (FootballMatch $m) => [$m->kickoff_at->getTimestamp(), $m->id]);
        $upcoming = $scoped->filter(fn (FootballMatch $m) => in_array($m->status, [MatchStatus::Scheduled, MatchStatus::Live, MatchStatus::Postponed], true));

        return [
            'played' => $played->map($row)->values()->all(),
            'upcoming' => $upcoming->sortBy(fn (FootballMatch $m) => [$m->kickoff_at->getTimestamp(), $m->id])->map($row)->values()->all(),
        ];
    }

    /**
     * Срезы сыгранных матчей по ключу турнира и контексту (все / дома / в гостях).
     *
     * @param  Collection<int, FootballMatch>  $finished
     * @param  array<int, string>  $keys
     * @param  list<array{key: string, name: string}>  $tournaments
     * @return array<string, array<string, Collection<int, FootballMatch>>>
     */
    private function slices(Collection $finished, Team $team, array $keys, array $tournaments): array
    {
        $slices = [];
        foreach ($tournaments as $tournament) {
            $inTournament = $tournament['key'] === self::ALL_KEY
                ? $finished
                : $finished->filter(fn (FootballMatch $m) => $keys[$m->competition_id] === $tournament['key']);

            foreach (self::CONTEXTS as $context => $side) {
                $slices[$tournament['key']][$context] = $side === null
                    ? $inTournament->values()
                    : $inTournament->filter(fn (FootballMatch $m) => ($m->home_team_id === $team->id) === ($side === 'home'))->values();
            }
        }

        return $slices;
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  array<int, string>  $keys
     * @param  Collection<int, Competition>  $competitions
     * @param  list<array{key: string, name: string}>  $tournaments
     * @return array<string, array<string, mixed>>
     */
    private function stats(Team $team, Collection $finished, array $keys, Collection $competitions, array $tournaments, string $locale): array
    {
        $matchIds = $finished->pluck('id')->all();
        $events = $this->catalog->teamEvents($team->id, $matchIds);
        $cards = [];
        $penalties = [];
        foreach ($events as $event) {
            $cards[$event->match_id] ??= ['yellow' => 0, 'red' => 0];
            match ($event->type) {
                MatchEventType::Yellow => $cards[$event->match_id]['yellow']++,
                MatchEventType::SecondYellow => [$cards[$event->match_id]['yellow']++, $cards[$event->match_id]['red']++],
                MatchEventType::Red => $cards[$event->match_id]['red']++,
                MatchEventType::PenaltyGoal => $penalties[$event->match_id] = ($penalties[$event->match_id] ?? 0) + 1,
                default => null,
            };
        }

        $teamStats = collect($this->catalog->teamStatsForMatches($matchIds))
            ->where('team_id', $team->id)
            ->keyBy('match_id');
        $playerStats = $this->catalog->teamPlayerStats($team->id, $matchIds)->groupBy('match_id');

        $average = function (Collection $matches, string $field, int $digits) use ($teamStats): ?float {
            $values = $matches->map(fn (FootballMatch $m) => $teamStats[$m->id][$field] ?? null)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : round($values->avg(), $digits);
        };

        $minutes = function (Collection $matches) use ($playerStats): ?int {
            $durations = $matches
                ->map(fn (FootballMatch $m) => ($playerStats[$m->id] ?? collect())->pluck('minutes')->filter(fn ($v) => $v !== null)->max())
                ->filter(fn ($v) => $v !== null);

            return $durations->isEmpty() ? null : (int) $durations->sum();
        };

        $line = function (string $name, string $key, Collection $matches) use ($team, $cards, $penalties, $average, $minutes): array {
            return [
                'key' => $key,
                'name' => $name,
                'apps' => $matches->count(),
                'goals' => $matches->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->home_goals : $m->away_goals),
                'penalty_goals' => $matches->sum(fn (FootballMatch $m) => $penalties[$m->id] ?? 0),
                'conceded' => $matches->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->away_goals : $m->home_goals),
                'shots' => $average($matches, 'shots', 1),
                'yellow' => $matches->sum(fn (FootballMatch $m) => $cards[$m->id]['yellow'] ?? 0),
                'red' => $matches->sum(fn (FootballMatch $m) => $cards[$m->id]['red'] ?? 0),
                'possession' => $average($matches, 'possession_pct', 1),
                'passing' => $average($matches, 'pass_accuracy_pct', 1),
                'minutes' => $minutes($matches),
                'rating' => $average($matches, 'rating', 2),
            ];
        };

        $stats = [];
        foreach (self::CONTEXTS as $context => $side) {
            $inContext = $side === null
                ? $finished
                : $finished->filter(fn (FootballMatch $m) => ($m->home_team_id === $team->id) === ($side === 'home'));

            $rows = [];
            foreach ($competitions as $competition) {
                $rows[] = $line(
                    $competition->localized('short_name', $locale) ?: $competition->localized('name', $locale),
                    $competition->slug,
                    $inContext->where('competition_id', $competition->id),
                );
            }

            $stats[$context] = ['rows' => $rows, 'total' => $line('Всего', self::ALL_KEY, $inContext)];
        }

        return $stats;
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  array<int, string>  $keys
     * @param  list<array{key: string, name: string}>  $tournaments
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function goals(Collection $finished, Team $team, array $keys, array $tournaments): array
    {
        $goals = [];
        foreach ($this->slices($finished, $team, $keys, $tournaments) as $key => $byContext) {
            foreach ($byContext as $context => $matches) {
                $goals[$key][$context] = $this->goalSplit($matches, $team);
            }
        }

        return $goals;
    }

    /**
     * Голы и рынки, посчитанные по счетам сыгранных матчей среза.
     *
     * @param  Collection<int, FootballMatch>  $matches
     * @return array<string, mixed>
     */
    private function goalSplit(Collection $matches, Team $team): array
    {
        $views = $matches->map(function (FootballMatch $m) use ($team): array {
            $isHome = $m->home_team_id === $team->id;

            return ['gf' => $isHome ? $m->home_goals : $m->away_goals, 'ga' => $isHome ? $m->away_goals : $m->home_goals];
        });

        $n = $views->count();
        $scored = $views->sum('gf');
        $conceded = $views->sum('ga');
        $per = fn (int $goals): ?float => $n > 0 ? round($goals / $n, 2) : null;

        $market = function (string $key, string $title, string $left, string $right, callable $test) use ($views, $n): array {
            $count = $views->filter($test)->count();
            $leftPct = $n > 0 ? (int) round($count / $n * 100) : null;

            return [
                'key' => $key,
                'title' => $title,
                'left_label' => $left,
                'right_label' => $right,
                'left' => $count,
                'right' => $n - $count,
                'left_pct' => $leftPct,
                'right_pct' => $leftPct === null ? null : 100 - $leftPct,
            ];
        };

        return [
            'matches' => $n,
            'scored' => $scored,
            'conceded' => $conceded,
            'scored_per_match' => $per($scored),
            'conceded_per_match' => $per($conceded),
            'markets' => [
                $market('total_1_5', '1.5', 'Менее', 'Более', fn (array $m) => $m['gf'] + $m['ga'] <= 1),
                $market('total_2_5', '2.5', 'Менее', 'Более', fn (array $m) => $m['gf'] + $m['ga'] <= 2),
                $market('total_3_5', '3.5', 'Менее', 'Более', fn (array $m) => $m['gf'] + $m['ga'] <= 3),
                $market('btts', 'Обе забьют', 'Да', 'Нет', fn (array $m) => $m['gf'] > 0 && $m['ga'] > 0),
                $market('clean_sheet', 'Сухой матч', 'Да', 'Нет', fn (array $m) => $m['ga'] === 0),
            ],
        ];
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  array<int, string>  $keys
     * @param  list<array{key: string, name: string}>  $tournaments
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function players(Team $team, Collection $finished, array $keys, array $tournaments, string $locale): array
    {
        $matchIds = $finished->pluck('id')->all();
        $starters = $this->catalog->starters($team->id, $matchIds);
        $events = $this->catalog->teamEvents($team->id, $matchIds);

        $playerIds = $starters->pluck('player_id')
            ->merge($events->pluck('player_id'))
            ->merge($events->pluck('related_player_id'))
            ->filter()->unique()->values()->all();
        $playerStats = $this->catalog->teamPlayerStats($team->id, $matchIds);
        $playerIds = array_values(array_unique(array_merge($playerIds, $playerStats->pluck('player_id')->all())));
        $roster = $this->catalog->currentSquad($team->id);
        $playerIds = array_values(array_unique(array_merge($playerIds, $roster->keys()->all())));
        $players = $this->catalog->playersByIds($playerIds);
        $shirtNumbers = $this->catalog->latestShirtNumbers($team->id);

        $result = [];
        foreach ($this->slices($finished, $team, $keys, $tournaments) as $key => $byContext) {
            foreach ($byContext as $context => $matches) {
                $result[$key][$context] = ClubPlayersPayload::make(
                    array_fill_keys($matches->pluck('id')->all(), true),
                    $starters,
                    $events,
                    $players,
                    $locale,
                    $playerStats,
                    $roster,
                    $shirtNumbers,
                );
            }
        }

        return $result;
    }
}
