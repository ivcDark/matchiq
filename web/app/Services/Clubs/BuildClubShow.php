<?php

namespace App\Services\Clubs;

use App\Domains\Matches\LeagueTable;
use App\Domains\Tournaments\FinishedMatch;
use App\Domains\Tournaments\SeasonLabel;
use App\Enums\CompetitionStatus;
use App\Enums\MatchEventType;
use App\Enums\MatchResult;
use App\Enums\MatchStatus;
use App\Http\Responses\Clubs\ClubPlayersPayload;
use App\Http\Responses\Clubs\ClubShowResponse;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Repositories\CompetitionCatalogRepository;
use App\Repositories\MatchCatalogRepository;
use App\Repositories\TournamentCatalogRepository;
use Illuminate\Support\Collection;

/**
 * Страница клуба: место в таблице, матчи, голы, карточки и игроки считаются из матчей и событий БД.
 * Кубок и показатели, которых в БД нет (удары, владение, рейтинги, минуты), — пустые значения.
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
    ) {}

    public function handle(int $teamId): ?ClubShowResponse
    {
        $team = $this->catalog->findTeam($teamId);
        if ($team === null) {
            return null;
        }

        $locale = config('matchiq.ui_locale');
        $teamMatches = $this->catalog->matchesForTeam($team->id);
        $competitions = $this->competitions->visibleByIds($teamMatches->pluck('competition_id')->unique()->values()->all());

        $seasons = [];
        foreach ($competitions as $competition) {
            $seasons[$competition->id] = $this->matches->currentSeason($competition->id);
        }

        $scoped = $teamMatches->filter(
            fn (FootballMatch $m) => isset($seasons[$m->competition_id]) && $seasons[$m->competition_id]->id === $m->season_id,
        )->values();

        $primary = $competitions
            ->sortBy(fn (Competition $c) => $c->status === CompetitionStatus::Live ? 0 : 1)
            ->first();

        $keys = $competitions->mapWithKeys(fn (Competition $c) => [$c->id => $c->slug])->all();
        $tournaments = $this->tournaments($competitions, $locale);
        $finished = $scoped->filter(fn (FootballMatch $m) => FinishedMatch::is($m))->values();

        $opponents = $this->catalog->teamsByIds(
            $scoped->map(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->away_team_id : $m->home_team_id)->unique()->values()->all(),
        );

        $fixtures = $this->fixtures($scoped, $team, $opponents, $keys, $competitions, $locale);

        $primarySeason = $primary === null ? null : $seasons[$primary->id];
        $primaryScoped = $primary === null ? new Collection : $scoped->where('competition_id', $primary->id);

        return new ClubShowResponse([
            'club' => $this->club($team, $primary, $primarySeason, $primaryScoped, $locale),
            'standing' => $this->standing($team, $primary, $primarySeason, $locale),
            'cup' => null,
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
    private function standing(Team $team, ?Competition $primary, ?Season $season, string $locale): array
    {
        $empty = [
            'tournament_id' => $primary?->id,
            'tournament' => $primary === null ? '' : ($primary->localized('short_name', $locale) ?: $primary->localized('name', $locale)),
            'position' => null, 'teams' => 0, 'zone' => null, 'zone_label' => null,
            'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0,
            'form' => [], 'behind_leader' => null, 'neighbors' => [],
        ];

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
                'stage' => $m->round?->number === null ? '' : $m->round->number.' тур',
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
        $events = $this->catalog->teamEvents($team->id, $finished->pluck('id')->all());
        $cards = [];
        foreach ($events as $event) {
            $cards[$event->match_id] ??= ['yellow' => 0, 'red' => 0];
            match ($event->type) {
                MatchEventType::Yellow => $cards[$event->match_id]['yellow']++,
                MatchEventType::SecondYellow => [$cards[$event->match_id]['yellow']++, $cards[$event->match_id]['red']++],
                MatchEventType::Red => $cards[$event->match_id]['red']++,
                default => null,
            };
        }

        $line = function (string $name, string $key, Collection $matches) use ($team, $cards): array {
            return [
                'key' => $key,
                'name' => $name,
                'apps' => $matches->count(),
                'goals' => $matches->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->home_goals : $m->away_goals),
                'conceded' => $matches->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->away_goals : $m->home_goals),
                'shots' => null,
                'yellow' => $matches->sum(fn (FootballMatch $m) => $cards[$m->id]['yellow'] ?? 0),
                'red' => $matches->sum(fn (FootballMatch $m) => $cards[$m->id]['red'] ?? 0),
                'possession' => null,
                'passing' => null,
                'aerial' => null,
                'rating' => null,
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
        $players = $this->catalog->playersByIds($playerIds);

        $result = [];
        foreach ($this->slices($finished, $team, $keys, $tournaments) as $key => $byContext) {
            foreach ($byContext as $context => $matches) {
                $result[$key][$context] = ClubPlayersPayload::make(
                    array_fill_keys($matches->pluck('id')->all(), true),
                    $starters,
                    $events,
                    $players,
                    $locale,
                );
            }
        }

        return $result;
    }
}
