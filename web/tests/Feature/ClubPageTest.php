<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchEventType;
use App\Models\Competition;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesLeagueData;
use Tests\TestCase;

class ClubPageTest extends TestCase
{
    use CreatesLeagueData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-27 12:00:00');
        $this->createLeague();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function props(string $team = 'zenit'): array
    {
        return $this->get('/clubs/'.$this->teams[$team]->id)->assertOk()->viewData('page')['props'];
    }

    private function addCup(): Competition
    {
        $cup = Competition::create([
            'country_id' => $this->country->id, 'slug' => 'cup', 'name' => 'Russian Cup', 'name_i18n' => ['ru' => 'Кубок России'],
            'short_name' => 'Cup', 'short_name_i18n' => ['ru' => 'Кубок'],
            'type' => CompetitionType::Cup, 'status' => CompetitionStatus::Live, 'sort_order' => 20,
        ]);
        $season = $this->createSeason($cup, '2026-2027', true);
        $this->createStage($season);

        return $cup->setRelation('currentSeason', $season);
    }

    public function test_unknown_non_numeric_and_team_without_page_return_404(): void
    {
        $this->get('/clubs/999')->assertNotFound();
        $this->get('/clubs/zenit')->assertNotFound();
    }

    public function test_club_without_matches_renders_empty_placeholders(): void
    {
        $this->get('/clubs/'.$this->teams['zenit']->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clubs/Show')
                ->where('club.id', $this->teams['zenit']->id)
                ->where('club.name', 'Зенит')
                ->where('club.country', 'Россия')
                ->where('club.city', 'Москва')
                ->where('club.tournament_id', null)
                ->where('standing.position', null)
                ->where('standing.neighbors', [])
                ->where('cup', null)
                ->where('last_match', null)
                ->where('next_match', null)
                ->where('calendar', [])
                ->where('results', [])
                ->where('tournaments', [['key' => 'all', 'name' => 'Все матчи']]));
    }

    public function test_club_page_reads_standing_and_header_from_database(): void
    {
        $this->playRounds();
        $props = $this->props();

        $this->assertSame($this->teams['zenit']->id, $props['club']['id']);
        $this->assertSame($this->competition->id, $props['club']['tournament_id']);
        $this->assertSame('2026/27', $props['club']['season']);
        $this->assertSame(2, $props['club']['round']);
        $this->assertSame(3, $props['club']['rounds_total']);

        $standing = $props['standing'];
        $this->assertSame($this->competition->id, $standing['tournament_id']);
        $this->assertSame('РПЛ', $standing['tournament']);
        $this->assertSame(
            ['position' => 3, 'teams' => 4, 'played' => 2, 'wins' => 1, 'draws' => 0, 'losses' => 1, 'gf' => 4, 'ga' => 3, 'gd' => 1, 'pts' => 3],
            array_intersect_key($standing, array_flip(['position', 'teams', 'played', 'wins', 'draws', 'losses', 'gf', 'ga', 'gd', 'pts'])),
        );
        $this->assertSame(['В', 'П'], $standing['form']);
        $this->assertSame(1, $standing['behind_leader']);
        $this->assertSame(['Динамо', 'ЦСКА', 'Зенит', 'Спартак'], array_column($standing['neighbors'], 'team'));
        $this->assertSame([false, false, true, false], array_column($standing['neighbors'], 'is_current'));
        $this->assertSame($this->teams['dynamo']->id, $standing['neighbors'][0]['team_id']);
    }

    public function test_standing_spot_matches_tournament_table(): void
    {
        $this->playRounds();
        $table = $this->get('/tournaments/'.$this->competition->id)->viewData('page')['props']['standings'];

        foreach ($table as $row) {
            $standing = $this->get('/clubs/'.$row['team_id'])->viewData('page')['props']['standing'];
            $this->assertSame($row['pos'], $standing['position'], $row['team']);
            $this->assertSame($row['pts'], $standing['pts'], $row['team']);
            $this->assertSame($row['gd'], $standing['gd'], $row['team']);
        }
    }

    public function test_fixtures_split_into_results_desc_and_calendar_asc_with_last_and_next(): void
    {
        $this->playRounds();
        $props = $this->props();

        $this->assertSame(['Динамо', 'Спартак'], array_column($props['results'], 'opponent'));
        $this->assertSame(['2026-11-15', '2026-11-07'], array_column($props['results'], 'date'));
        $this->assertSame(['П', 'В'], array_column($props['results'], 'result'));
        $this->assertSame(['premier-league', 'premier-league'], array_column($props['results'], 'tournament'));
        $this->assertSame(['2 тур', '1 тур'], array_column($props['results'], 'stage'));
        $this->assertSame([2, 3, 3, 2], [$props['results'][0]['gf'], $props['results'][0]['ga'], $props['results'][0]['home_score'], $props['results'][0]['away_score']]);
        $this->assertSame('away', $props['results'][0]['venue']);
        $this->assertSame($props['results'][0], $props['last_match']);

        $this->assertCount(1, $props['calendar']);
        $this->assertSame($props['calendar'][0], $props['next_match']);
        $this->assertSame(['ЦСКА', $this->teams['cska']->id, 'home', '2026-12-05', '14:00'], [
            $props['next_match']['opponent'], $props['next_match']['opponent_id'], $props['next_match']['venue'],
            $props['next_match']['date'], $props['next_match']['time'],
        ]);
        $this->assertArrayNotHasKey('home_score', $props['next_match']);
    }

    public function test_cards_goals_markets_and_stats_come_from_matches_and_events(): void
    {
        $matches = $this->playRounds();
        $sobolev = $this->player('sobolev', 'А. Соболев');
        $glushenkov = $this->player('glushenkov', 'М. Глушенков');
        $this->event($matches['zenit-spartak'], 'zenit', MatchEventType::Goal, $sobolev, $glushenkov, 10);
        $this->event($matches['zenit-spartak'], 'zenit', MatchEventType::Red, $sobolev, null, 80);
        $this->event($matches['dynamo-zenit'], 'zenit', MatchEventType::Yellow, $glushenkov, null, 40);
        $this->event($matches['dynamo-zenit'], 'zenit', MatchEventType::Substitution, $sobolev, $glushenkov, 60);
        $this->event($matches['dynamo-zenit'], 'dynamo', MatchEventType::Yellow, $this->player('dyn', 'Д. Динамовец'), null, 41);
        $this->starter($matches['zenit-spartak'], 'zenit', $sobolev);

        $props = $this->props();

        $total = $props['stats']['overall']['total'];
        $this->assertSame([2, 4, 3, 1, 1], [$total['apps'], $total['goals'], $total['conceded'], $total['yellow'], $total['red']]);
        $this->assertNull($total['shots']);
        $this->assertNull($total['possession']);
        $this->assertNull($total['rating']);
        $this->assertSame('premier-league', $props['stats']['overall']['rows'][0]['key']);
        $this->assertSame(1, $props['stats']['home']['total']['apps']);
        $this->assertSame(1, $props['stats']['away']['total']['apps']);
        $this->assertSame(
            $total['apps'],
            $props['stats']['home']['total']['apps'] + $props['stats']['away']['total']['apps'],
        );

        $goals = $props['goals']['premier-league']['overall'];
        $this->assertSame([2, 4, 3, 2.0, 1.5], [$goals['matches'], $goals['scored'], $goals['conceded'], $goals['scored_per_match'], $goals['conceded_per_match']]);
        $markets = collect($goals['markets'])->keyBy('key');
        $this->assertSame([0, 2, 0, 100], [$markets['total_1_5']['left'], $markets['total_1_5']['right'], $markets['total_1_5']['left_pct'], $markets['total_1_5']['right_pct']]);
        $this->assertSame([1, 50], [$markets['btts']['left'], $markets['btts']['left_pct']]);
        $this->assertSame([1, 50], [$markets['clean_sheet']['left'], $markets['clean_sheet']['left_pct']]);
        $this->assertSame(['Менее', 'Более'], [$markets['total_2_5']['left_label'], $markets['total_2_5']['right_label']]);

        $players = $props['players']['premier-league']['overall'];
        $this->assertSame(['А. Соболев', 'М. Глушенков'], array_column($players, 'name'));
        $this->assertSame([1, 1, 0, 1, 0, 0, 1], [$players[0]['apps'], $players[0]['starts'], $players[0]['subs'], $players[0]['goals'], $players[0]['assists'], $players[0]['yellow'], $players[0]['red']]);
        $this->assertSame([1, 0, 1, 0, 1, 1, 0], [$players[1]['apps'], $players[1]['starts'], $players[1]['subs'], $players[1]['goals'], $players[1]['assists'], $players[1]['yellow'], $players[1]['red']]);
        $this->assertSame('FW', $players[0]['position']);
        $this->assertNull($players[0]['minutes']);
        $this->assertNull($players[0]['rating']);

        $this->assertSame(1, $props['goals']['premier-league']['home']['matches']);
        $this->assertSame(1, $props['goals']['premier-league']['away']['matches']);
    }

    public function test_second_competition_adds_all_matches_tab_and_aggregates(): void
    {
        $this->playRounds();
        $cup = $this->addCup();
        $this->match('zenit', 'cska', '2026-11-20 14:00:00', [1, 1], 1, ['season' => $cup->currentSeason]);

        $props = $this->props();

        $this->assertSame(['premier-league', 'cup', 'all'], array_column($props['tournaments'], 'key'));
        $this->assertSame(['РПЛ', 'Кубок', 'Все матчи'], array_column($props['tournaments'], 'name'));
        $this->assertSame($this->competition->id, $props['club']['tournament_id'], 'основной турнир — лига');
        $this->assertSame(2, $props['goals']['premier-league']['overall']['matches']);
        $this->assertSame(1, $props['goals']['cup']['overall']['matches']);
        $this->assertSame(3, $props['goals']['all']['overall']['matches']);
        $this->assertSame(3, $props['stats']['overall']['total']['apps']);
        $this->assertSame(['premier-league', 'cup'], array_column($props['stats']['overall']['rows'], 'key'));
        $this->assertSame(['cup', 'premier-league', 'premier-league'], array_column($props['results'], 'tournament'));
        $this->assertNull($props['cup'], 'данных о кубковой стадии в БД нет');
    }

    public function test_only_current_season_matches_are_shown(): void
    {
        $this->playRounds();
        $old = $this->createSeason($this->competition, '2025-2026', false);
        $this->createStage($old);
        $this->match('zenit', 'dynamo', '2026-05-10 14:00:00', [5, 0], 30, ['season' => $old]);

        $props = $this->props();

        $this->assertCount(2, $props['results']);
        $this->assertSame(2, $props['goals']['premier-league']['overall']['matches']);
        $this->assertSame(3, $props['club']['rounds_total']);
    }

    public function test_hidden_competition_matches_are_not_exposed(): void
    {
        $hidden = $this->competition->replicate();
        $hidden->fill(['slug' => 'secret', 'status' => CompetitionStatus::Hidden])->save();
        $season = $this->createSeason($hidden, '2026-2027', true);
        $this->createStage($season);
        $this->match('zenit', 'cska', '2026-11-20 14:00:00', [1, 1], 1, ['season' => $season]);

        $props = $this->props();

        $this->assertSame([], $props['results']);
        $this->assertSame([['key' => 'all', 'name' => 'Все матчи']], $props['tournaments']);
    }

    public function test_every_standings_team_has_a_working_club_page(): void
    {
        $this->playRounds();
        $table = $this->get('/tournaments/'.$this->competition->id)->viewData('page')['props']['standings'];

        $this->assertCount(4, $table);
        foreach ($table as $row) {
            $this->assertNotNull(Team::find($row['team_id']));
            $this->get('/clubs/'.$row['team_id'])->assertOk()->assertInertia(fn ($page) => $page
                ->where('club.id', $row['team_id'])
                ->where('club.name', $row['team']));
        }
    }

    public function test_club_links_are_wired_in_vue(): void
    {
        $standings = file_get_contents(resource_path('js/Components/TournamentStandings.vue'));
        $this->assertStringContainsString("route('clubs.show', row.team_id)", $standings);

        $match = file_get_contents(resource_path('js/Pages/Matches/Show.vue'));
        $this->assertStringContainsString("route('clubs.show', match.home_club_id)", $match);
        $this->assertStringContainsString("route('clubs.show', match.away_club_id)", $match);
    }

    public function test_club_nav_has_only_summary_calendar_results_without_statistics_tab(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Clubs/Show.vue'));
        $this->assertStringContainsString("{ id: 'summary', label: 'Сводка' }", $show);
        $this->assertStringContainsString("{ id: 'calendar', label: 'Календарь' }", $show);
        $this->assertStringContainsString("{ id: 'results', label: 'Результаты' }", $show);
        $this->assertStringNotContainsString("id: 'stats'", $show);
        $this->assertStringContainsString('<ClubFixtures title="Календарь" mode="calendar" :matches="calendar"', $show);
        $this->assertStringContainsString('<ClubFixtures title="Результаты" mode="results" :matches="results"', $show);
        $this->assertStringContainsString('<ClubCupStatus v-if="cup"', $show);
    }
}
