<?php

namespace App\Services\Tournaments;

use App\Domains\Matches\LeagueTable;
use App\Domains\Tournaments\FinishedMatch;
use App\Domains\Tournaments\PlayerLeaders;
use App\Domains\Tournaments\PlayerName;
use App\Domains\Tournaments\SeasonLabel;
use App\Domains\Tournaments\TeamStatsLeaders;
use App\Enums\MatchResult;
use App\Enums\MatchStatus;
use App\Enums\StageType;
use App\Http\Responses\Tournaments\RoundBlocksPayload;
use App\Http\Responses\Tournaments\TournamentShowResponse;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Season;
use App\Models\Team;
use App\Repositories\CompetitionCatalogRepository;
use App\Repositories\TournamentCatalogRepository;
use Illuminate\Support\Collection;

/**
 * Страница турнира: таблица, форма, расписание, результаты и бомбардиры считаются из матчей и событий БД.
 * «Лучшие команды» — средние по `match_team_stats` (владение, удары, точность пасов, карточки, рейтинг состава).
 * «Скорострельные», вратари, минуты и рейтинги игроков — из `match_player_stats` (см. `PlayerLeaders`); без данных блоки пустые.
 */
class BuildTournamentShow
{
    private const TOP_LIMIT = 5;

    private const CONTRIBUTION_LIMIT = 10;

    private const CONTEXTS = ['overall' => null, 'home' => 'home', 'away' => 'away'];

    public function __construct(
        private readonly CompetitionCatalogRepository $competitions,
        private readonly TournamentCatalogRepository $catalog,
    ) {}

    public function handle(int $competitionId, ?string $seasonKey): ?TournamentShowResponse
    {
        $competition = $this->competitions->findVisible($competitionId);
        if ($competition === null) {
            return null;
        }

        $seasons = $this->catalog->seasons($competition->id);
        $season = ($seasonKey !== null ? $seasons->firstWhere('slug', $seasonKey) : null) ?? $seasons->first();

        $matches = $season === null ? new Collection : $this->catalog->matchesInSeason($season->id);
        $finished = $matches->filter(fn (FootballMatch $m) => FinishedMatch::is($m))->values();

        $tables = [];
        foreach (self::CONTEXTS as $context => $side) {
            $tables[$context] = LeagueTable::compute($finished, $side);
        }

        $teams = $this->teamsFor($matches);
        $locale = config('matchiq.ui_locale');
        $name = fn (int $teamId): string => $teams[$teamId]->localized('name', $locale);

        $standingsByContext = array_map(
            fn (array $table) => array_map(fn (array $row) => [
                'pos' => $row['pos'],
                'team' => $name($row['team_id']),
                'team_id' => $row['team_id'],
                'played' => $row['played'],
                'wins' => $row['wins'],
                'draws' => $row['draws'],
                'losses' => $row['losses'],
                'gf' => $row['gf'],
                'ga' => $row['ga'],
                'gd' => $row['gd'],
                'pts' => $row['pts'],
                'form' => $row['form'],
                'zone' => null,
            ], $table),
            $tables,
        );

        $round = (int) ($finished->map(fn (FootballMatch $m) => $m->round?->number ?? 0)->max() ?? 0);
        $roundsTotal = $season?->rounds_total ?? (int) ($matches->map(fn (FootballMatch $m) => $m->round?->number ?? 0)->max() ?? 0);

        $leaders = $this->leaders($finished, $tables['overall'], $teams, $locale);

        return new TournamentShowResponse([
            'tournament' => $this->header($competition, $season, $matches, $finished, $tables['overall'], $standingsByContext['overall'], $round, $roundsTotal),
            'seasonKey' => $season?->slug ?? '',
            'seasons' => $seasons->map(fn (Season $s) => [
                'key' => $s->slug,
                'label' => SeasonLabel::format($s->label),
                'current' => (bool) $s->is_current,
                'rounds_total' => $s->rounds_total,
            ])->values()->all(),
            'zones' => [],
            'standings' => $standingsByContext['overall'],
            'standingsByContext' => $standingsByContext,
            'teamForm' => $this->teamForm($finished, $standingsByContext['overall'], $name),
            'schedule' => $this->schedule($matches, $name),
            'results' => $this->results($finished, $name),
            'leaders' => $leaders['lists'] + $this->playerLeaders($finished, $teams, $locale),
            'attackDefense' => $this->attackDefense($standingsByContext['overall']),
            'teamStats' => $this->teamStats($finished, $name),
            'contribution' => ['overall' => $leaders['contribution'], 'home' => [], 'away' => []],
        ]);
    }

    /**
     * @param  Collection<int, FootballMatch>  $matches
     * @return Collection<int, Team>
     */
    private function teamsFor(Collection $matches): Collection
    {
        $ids = $matches->flatMap(fn (FootballMatch $m) => [$m->home_team_id, $m->away_team_id])->unique()->values()->all();

        return $this->catalog->teamsByIds($ids);
    }

    /**
     * @param  Collection<int, FootballMatch>  $matches
     * @param  Collection<int, FootballMatch>  $finished
     * @param  list<array<string, mixed>>  $table
     * @param  list<array<string, mixed>>  $standings
     * @return array<string, mixed>
     */
    private function header(Competition $competition, ?Season $season, Collection $matches, Collection $finished, array $table, array $standings, int $round, int $roundsTotal): array
    {
        $locale = config('matchiq.ui_locale');
        $name = $competition->localized('name', $locale);
        $goals = $finished->sum(fn (FootballMatch $m) => $m->home_goals + $m->away_goals);
        $leader = $standings[0] ?? null;
        $second = $standings[1] ?? null;
        $stage = $season === null ? null : $this->catalog->firstStage($season->id);

        return [
            'id' => $competition->id,
            'name' => $competition->localized('short_name', $locale) ?: $name,
            'full_name' => $name,
            'country' => $competition->country?->localized('name', $locale) ?? '',
            'season' => $season === null ? '' : SeasonLabel::format($season->label),
            'season_key' => $season?->slug ?? '',
            'round' => $round,
            'next_round' => $round + 1,
            'rounds_total' => $roundsTotal,
            'stage' => match (true) {
                $stage === null => '',
                $stage->type === StageType::League => 'Регулярный чемпионат',
                default => (string) $stage->name,
            },
            'summary' => [
                'teams' => count($table) ?: $this->teamsFor($matches)->count(),
                'matches' => $finished->count(),
                'goals' => $goals,
                'goals_per_match' => $finished->isEmpty() ? 0 : round($goals / $finished->count(), 2),
                'leader' => $leader === null ? null : ['team' => $leader['team'], 'pts' => $leader['pts']],
                'gap' => $leader !== null && $second !== null ? $leader['pts'] - $second['pts'] : null,
            ],
        ];
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  list<array<string, mixed>>  $standings
     * @return list<array<string, mixed>>
     */
    private function teamForm(Collection $finished, array $standings, callable $name): array
    {
        $byTeam = [];
        foreach ($finished->sortBy(fn (FootballMatch $m) => [$m->kickoff_at->getTimestamp(), $m->id]) as $match) {
            foreach ([[$match->home_team_id, $match->away_team_id, $match->home_goals, $match->away_goals, 'home'],
                [$match->away_team_id, $match->home_team_id, $match->away_goals, $match->home_goals, 'away']] as [$teamId, $opponentId, $for, $against, $venue]) {
                $byTeam[$teamId][] = [
                    'round' => $match->round?->number,
                    'result' => MatchResult::fromGoals($for, $against)->value,
                    'venue' => $venue,
                    'opponent' => $name($opponentId),
                    'score' => $for.':'.$against,
                    'date' => $match->kickoff_at->toDateString(),
                ];
            }
        }

        return array_map(fn (array $row) => [
            'pos' => $row['pos'],
            'team' => $row['team'],
            'team_id' => $row['team_id'],
            'matches' => $byTeam[$row['team_id']] ?? [],
        ], $standings);
    }

    /**
     * Предстоящие туры по возрастанию; вероятности — только из текущего прогноза модели, если он есть.
     *
     * @param  Collection<int, FootballMatch>  $matches
     * @return list<array<string, mixed>>
     */
    private function schedule(Collection $matches, callable $name): array
    {
        $upcoming = $matches->filter(fn (FootballMatch $m) => in_array($m->status, [MatchStatus::Scheduled, MatchStatus::Live, MatchStatus::Postponed], true));
        $nextRound = $upcoming->map(fn (FootballMatch $m) => $m->round?->number)->filter()->min();

        $blocks = RoundBlocksPayload::make($upcoming, function (FootballMatch $m) use ($name): array {
            $row = [
                'id' => $m->id,
                'home' => $name($m->home_team_id),
                'away' => $name($m->away_team_id),
                'date' => $m->kickoff_at->toDateString(),
                'time' => $m->kickoff_at->format('H:i'),
            ];
            $prediction = $m->currentPrediction;
            if ($prediction !== null) {
                $pct = static fn ($p): int => (int) round(((float) $p) * 100);
                $row += [
                    'prob_home' => $pct($prediction->p_home),
                    'prob_draw' => $pct($prediction->p_draw),
                    'prob_away' => $pct($prediction->p_away),
                ];
            }

            return $row;
        });

        return array_map(fn (array $block) => $block + ['is_next' => $nextRound !== null && $block['round'] === $nextRound], $blocks);
    }

    /**
     * Сыгранные туры от последнего к первому.
     *
     * @param  Collection<int, FootballMatch>  $finished
     * @return list<array<string, mixed>>
     */
    private function results(Collection $finished, callable $name): array
    {
        $blocks = RoundBlocksPayload::make($finished, fn (FootballMatch $m) => [
            'id' => $m->id,
            'home' => $name($m->home_team_id),
            'away' => $name($m->away_team_id),
            'home_goals' => $m->home_goals,
            'away_goals' => $m->away_goals,
            'date' => $m->kickoff_at->toDateString(),
            'time' => $m->kickoff_at->format('H:i'),
        ]);

        return array_reverse($blocks);
    }

    /**
     * @param  list<array<string, mixed>>  $standings
     * @return array<string, list<array<string, mixed>>>
     */
    private function attackDefense(array $standings): array
    {
        $build = function (string $field, bool $descending) use ($standings): array {
            $rows = $standings;
            usort($rows, fn (array $a, array $b) => ($descending ? $b[$field] <=> $a[$field] : $a[$field] <=> $b[$field]) ?: $a['pos'] <=> $b['pos']);

            $out = [];
            foreach (array_slice($rows, 0, self::TOP_LIMIT) as $i => $row) {
                $out[] = [
                    'rank' => $i + 1,
                    'team' => $row['team'],
                    'goals' => $row[$field],
                    'per_match' => round($row[$field] / max(1, $row['played']), 2),
                ];
            }

            return $out;
        };

        return [
            'best_attack' => $build('gf', true),
            'best_defense' => $build('ga', false),
            'worst_attack' => $build('gf', false),
            'worst_defense' => $build('ga', true),
        ];
    }

    /**
     * Пустой объект, пока ни у одного сыгранного матча нет командной статистики.
     *
     * @param  Collection<int, FootballMatch>  $finished
     */
    private function teamStats(Collection $finished, callable $name): array|object
    {
        $sides = [];
        foreach ($finished as $match) {
            $sides[$match->id] = [$match->home_team_id => 'home', $match->away_team_id => 'away'];
        }

        $rows = [];
        foreach ($this->catalog->teamStatsForMatches(array_keys($sides)) as $row) {
            $side = $sides[$row['match_id']][$row['team_id']] ?? null;
            if ($side !== null) {
                $rows[] = ['side' => $side] + $row;
            }
        }
        if ($rows === []) {
            return (object) [];
        }

        $names = [];
        foreach ($rows as $row) {
            $names[$row['team_id']] ??= $name($row['team_id']);
        }

        return TeamStatsLeaders::compute($rows, $names);
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  Collection<int, Team>  $teams
     * @return array{fastScorers: list<array<string, mixed>>, goalkeepers: list<array<string, mixed>>, minutes: list<array<string, mixed>>, ratings: list<array<string, mixed>>}
     */
    private function playerLeaders(Collection $finished, Collection $teams, string $locale): array
    {
        $matchIds = $finished->pluck('id')->all();
        $totals = $this->catalog->playerTotals($matchIds);
        if ($totals === []) {
            return ['fastScorers' => [], 'goalkeepers' => [], 'minutes' => [], 'ratings' => []];
        }

        $players = [];
        foreach ($this->catalog->playersByIds(array_unique(array_column($totals, 'player_id'))) as $id => $player) {
            $players[$id] = ['name' => $player->localized('name', $locale), 'position' => $player->position?->value];
        }
        $teamNames = $teams->map(fn (Team $t) => $t->localized('name', $locale))->all();

        return PlayerLeaders::compute($totals, $this->catalog->goalkeeperMatches($matchIds), $players, $teamNames);
    }

    /**
     * @param  Collection<int, FootballMatch>  $finished
     * @param  list<array<string, mixed>>  $table
     * @param  Collection<int, Team>  $teams
     * @return array{lists: array<string, list<array<string, mixed>>>, contribution: list<array<string, mixed>>}
     */
    private function leaders(Collection $finished, array $table, Collection $teams, string $locale): array
    {
        $matchIds = $finished->pluck('id')->all();
        $contributions = $this->catalog->goalContributions($matchIds);
        if ($contributions === []) {
            return ['lists' => ['scorers' => [], 'assists' => []], 'contribution' => []];
        }

        $players = $this->catalog->playersByIds(array_column($contributions, 'player_id'));
        $apps = $this->catalog->appearances(array_column($contributions, 'player_id'), $matchIds);
        $teamGoals = array_column($table, 'gf', 'team_id');

        $rows = array_map(fn (array $c) => [
            'name' => PlayerName::short($players[$c['player_id']]->localized('name', $locale)),
            'team' => $teams[$c['team_id']]->localized('name', $locale),
            'team_id' => $c['team_id'],
            'position' => $players[$c['player_id']]->position?->value,
            'matches' => $apps[$c['player_id']] ?? 0,
            'goals' => $c['goals'],
            'pens' => $c['pens'],
            'assists' => $c['assists'],
            'ga' => $c['goals'] + $c['assists'],
            'player_id' => $c['player_id'],
        ], $contributions);

        $ranked = function (array $items, callable $compare, array $keys): array {
            usort($items, $compare);

            return array_map(
                fn (array $row, int $i) => ['rank' => $i + 1] + array_intersect_key($row, array_flip($keys)),
                $items,
                array_keys($items),
            );
        };

        $scorers = $ranked(
            array_values(array_filter($rows, fn (array $r) => $r['ga'] > 0)),
            fn (array $a, array $b) => [$b['ga'], $b['goals'], $a['player_id']] <=> [$a['ga'], $a['goals'], $b['player_id']],
            ['name', 'team', 'position', 'matches', 'goals', 'pens', 'assists', 'ga'],
        );
        $assists = $ranked(
            array_values(array_filter($rows, fn (array $r) => $r['assists'] > 0)),
            fn (array $a, array $b) => [$b['assists'], $a['player_id']] <=> [$a['assists'], $b['player_id']],
            ['name', 'team', 'position', 'matches', 'assists'],
        );

        $withGoals = array_values(array_filter($rows, fn (array $r) => $r['goals'] > 0 && ($teamGoals[$r['team_id']] ?? 0) > 0));
        usort($withGoals, fn (array $a, array $b) => [$b['goals'], $a['player_id']] <=> [$a['goals'], $b['player_id']]);
        $contribution = [];
        foreach (array_slice($withGoals, 0, self::CONTRIBUTION_LIMIT) as $i => $r) {
            $total = max($teamGoals[$r['team_id']], $r['goals']);
            $contribution[] = [
                'rank' => $i + 1,
                'name' => $r['name'],
                'team' => $r['team'],
                'goals' => $r['goals'],
                'team_goals' => $total,
                'share' => (int) round($r['goals'] / $total * 100),
            ];
        }

        return ['lists' => ['scorers' => $scorers, 'assists' => $assists], 'contribution' => $contribution];
    }
}
