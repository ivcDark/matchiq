<?php

namespace App\Services\Matches;

use App\Domains\Matches\DayFilter;
use App\Domains\Matches\LeagueSelection;
use App\Domains\Matches\LeagueTable;
use App\Domains\Matches\TeamForm;
use App\Enums\CompetitionStatus;
use App\Http\Responses\Matches\LeaguePayload;
use App\Http\Responses\Matches\MatchesIndexResponse;
use App\Http\Responses\Matches\MatchRowPayload;
use App\Models\Competition;
use App\Models\NewsItem;
use App\Repositories\CompetitionCatalogRepository;
use App\Repositories\MatchCatalogRepository;

/** Главная: лиги из БД, матчи выбранного дня выбранных лиг, виджеты по первой выбранной лиге. */
class BuildMatchesIndex
{
    private const STANDINGS_WIDGET_ROWS = 8;

    private const SCORERS_WIDGET_ROWS = 5;

    private const HOT_SCORERS_WIDGET_ROWS = 5;

    private const NEWS_WIDGET_ROWS = 5;

    public function __construct(
        private readonly CompetitionCatalogRepository $competitions,
        private readonly MatchCatalogRepository $matches,
    ) {}

    public function handle(mixed $leagueInput, ?string $dateInput, ?string $periodInput): MatchesIndexResponse
    {
        $visible = $this->competitions->listVisible();
        $active = $visible->where('status', CompetitionStatus::Live)->keyBy('slug');
        $activeSlugs = $active->keys()->all();

        $selection = LeagueSelection::resolve($leagueInput, $activeSlugs);
        $day = DayFilter::resolve($dateInput, $periodInput);

        $selectedSlugs = $selection->effective($activeSlugs);
        $competitionIds = $active
            ->filter(fn (Competition $c) => in_array($c->slug, $selectedSlugs, true))
            ->pluck('id')
            ->all();

        $listed = $this->matches->listInWindow($competitionIds, $day->windowStart(), $day->windowEnd());
        $form = new TeamForm($this->matches->finishedForTeams(
            $listed->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->unique()->values()->all(),
        ));

        $widgetSlug = $selection->widgetSlug($activeSlugs);

        return new MatchesIndexResponse(
            leagues: $visible->map(fn (Competition $c) => LeaguePayload::make($c))->values()->all(),
            matches: $listed->map(fn ($m) => MatchRowPayload::make($m, $form))->values()->all(),
            selection: $selection,
            day: $day,
            widgets: $this->widgets(
                $widgetSlug === null ? null : $active->get($widgetSlug),
                $this->hotScorers($active->pluck('id')->all()),
            ),
        );
    }

    /**
     * Таблица считается по сыгранным матчам; бомбардиры — из событий матчей.
     * Скорострелы — кросс-лига за год, не зависят от выбранной лиги. Новости — промо из news_items.
     *
     * @param  list<array{pos: int, player: string, team: string, league: string, goals: int}>  $hotScorers
     * @return array<string, mixed>
     */
    private function widgets(?Competition $competition, array $hotScorers): array
    {
        $empty = ['league' => null, 'standings' => [], 'scorers' => [], 'hotScorers' => $hotScorers, 'news' => $this->news()];

        if ($competition === null) {
            return $empty;
        }

        $season = $this->matches->currentSeason($competition->id);
        if ($season === null) {
            return ['league' => $this->widgetLeague($competition)] + $empty;
        }

        $locale = config('matchiq.ui_locale');
        $table = array_slice(
            LeagueTable::compute($this->matches->finishedInSeason($season->id)),
            0,
            self::STANDINGS_WIDGET_ROWS,
        );
        $teams = $this->matches->teamsByIds(array_column($table, 'team_id'));

        $standings = array_map(fn (array $row) => [
            'pos' => $row['pos'],
            'team' => $teams[$row['team_id']]->localized('name', $locale),
            'team_id' => $row['team_id'],
            'played' => $row['played'],
            'gd' => $row['gd'],
            'pts' => $row['pts'],
        ], $table);

        $scorers = [];
        foreach ($this->matches->topScorers($season->id, self::SCORERS_WIDGET_ROWS) as $i => $row) {
            $scorers[] = [
                'pos' => $i + 1,
                'player' => $row['player']->localized('name', $locale),
                'team' => $row['team']->localized('name', $locale),
                'goals' => $row['goals'],
            ];
        }

        return [
            'league' => $this->widgetLeague($competition),
            'standings' => $standings,
            'scorers' => $scorers,
            'hotScorers' => $hotScorers,
            'news' => $empty['news'],
        ];
    }

    /**
     * «Скорострелы»: больше всего голов за последние 12 месяцев среди всех активных лиг (голы из `match_events`).
     *
     * @param  list<int>  $competitionIds
     * @return list<array{pos: int, player: string, team: string, league: string, goals: int}>
     */
    private function hotScorers(array $competitionIds): array
    {
        $locale = (string) config('matchiq.ui_locale');
        $rows = $this->matches->topScorersSince($competitionIds, now()->subYear(), self::HOT_SCORERS_WIDGET_ROWS);

        return array_map(fn (array $row, int $i) => [
            'pos' => $i + 1,
            'player' => $row['player']->localized('name', $locale),
            'team' => $row['team']->localized('name', $locale),
            'league' => $row['competition']->localized('short_name', $locale) ?: $row['competition']->localized('name', $locale),
            'goals' => $row['goals'],
        ], $rows, array_keys($rows));
    }

    /**
     * Карточки блока «Новости»: последние опубликованные, не больше пяти.
     *
     * @return list<array{id: int, tag: string, title: string, ago: string}>
     */
    private function news(): array
    {
        $locale = (string) config('matchiq.ui_locale');

        return NewsItem::query()
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::NEWS_WIDGET_ROWS)
            ->get()
            ->map(fn (NewsItem $item) => [
                'id' => $item->id,
                'tag' => $item->tag,
                'title' => $item->title,
                'ago' => $item->published_at->locale($locale)->diffForHumans(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function widgetLeague(Competition $competition): array
    {
        $league = LeaguePayload::make($competition);

        return [
            'id' => $league['id'],
            'label' => LeaguePayload::label($competition),
            'short' => $league['short'],
            'tournament_id' => $league['tournament_id'],
        ];
    }
}
