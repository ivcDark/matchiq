<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StageType;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Round;
use App\Models\Season;
use App\Models\Stage;
use App\Models\StageGroup;
use App\Models\StageTeam;
use App\Models\StandingZone;
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
                ->where('competitions', [])
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
        $this->assertSame(['premier-league', 'cup'], array_column($props['competitions'], 'key'));
        $this->assertSame(['league', 'cup'], array_column($props['competitions'], 'kind'));
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
        $this->assertStringContainsString('<ClubCupStatus v-else :cup="block"', $show);
    }

    private function cupStage(Competition $cup, StageType $type, string $name, int $order = 1): Stage
    {
        $season = $cup->currentSeason ?? Season::where('competition_id', $cup->id)->where('is_current', true)->first();

        return Stage::create([
            'season_id' => $season->id, 'name' => $name, 'type' => $type, 'stage_order' => $order,
            'has_standings' => $type->hasStandings(),
        ]);
    }

    private function cupCompetition(string $slug = 'cup', CompetitionType $type = CompetitionType::Cup): Competition
    {
        $cup = Competition::create([
            'country_id' => $this->country->id, 'slug' => $slug, 'name' => 'Russian Cup', 'name_i18n' => ['ru' => 'Кубок России'],
            'short_name' => 'Cup', 'short_name_i18n' => ['ru' => 'Кубок России'],
            'type' => $type, 'status' => CompetitionStatus::Live, 'sort_order' => 20,
        ]);
        $this->createSeason($cup, '2026-2027', true);

        return $cup->load('currentSeason');
    }

    /** @param  array<string, mixed>  $attrs */
    private function stageMatch(Competition $cup, Stage $stage, string $home, string $away, string $kickoff, ?array $score, array $attrs = []): FootballMatch
    {
        return FootballMatch::create($attrs + [
            'competition_id' => $cup->id, 'season_id' => $cup->currentSeason->id, 'stage_id' => $stage->id,
            'home_team_id' => $this->teams[$home]->id, 'away_team_id' => $this->teams[$away]->id,
            'kickoff_at' => $kickoff, 'status' => $score === null ? MatchStatus::Scheduled : MatchStatus::Finished,
            'home_goals' => $score[0] ?? null, 'away_goals' => $score[1] ?? null,
        ]);
    }

    private function competitionBlock(array $props, string $key): array
    {
        return collect($props['competitions'])->firstWhere('key', $key);
    }

    public function test_league_only_club_has_single_league_block_matching_standing(): void
    {
        $this->playRounds();
        $props = $this->props();

        $this->assertCount(1, $props['competitions']);
        $block = $props['competitions'][0];
        $this->assertSame(['premier-league', 'league', $this->competition->id, 'РПЛ'], [$block['key'], $block['kind'], $block['competition_id'], $block['name']]);
        $this->assertSame($props['standing'], $block['standing']);
        $this->assertSame(2, $block['standing']['round']);
    }

    public function test_cup_group_stage_shows_group_table_place_and_zone(): void
    {
        $this->playRounds();
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Group, 'Группы');
        $a = StageGroup::create(['stage_id' => $stage->id, 'name' => 'Группа A', 'group_order' => 1]);
        $b = StageGroup::create(['stage_id' => $stage->id, 'name' => 'Группа B', 'group_order' => 2]);
        foreach (['zenit', 'spartak', 'cska'] as $slug) {
            StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams[$slug]->id, 'stage_group_id' => $a->id]);
        }
        StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams['dynamo']->id, 'stage_group_id' => $b->id]);
        StandingZone::create(['stage_id' => $stage->id, 'from_position' => 1, 'to_position' => 2, 'code' => 'playoff', 'label' => 'Плей-офф']);

        $this->stageMatch($cup, $stage, 'zenit', 'spartak', '2026-10-20 14:00:00', [3, 0], ['stage_group_id' => $a->id]);
        $this->stageMatch($cup, $stage, 'cska', 'zenit', '2026-11-03 14:00:00', [1, 0], ['stage_group_id' => $a->id]);
        $this->stageMatch($cup, $stage, 'dynamo', 'dynamo', '2026-11-03 14:00:00', [9, 0], ['stage_group_id' => $b->id, 'away_team_id' => $this->teams['spartak']->id]);
        $this->stageMatch($cup, $stage, 'spartak', 'cska', '2026-12-08 14:00:00', null, ['stage_group_id' => $a->id]);
        $this->stageMatch($cup, $stage, 'zenit', 'cska', '2026-12-09 14:00:00', null, ['stage_group_id' => $a->id]);

        $props = $this->props();
        $block = $this->competitionBlock($props, 'cup');

        $this->assertSame(['premier-league', 'cup'], array_column($props['competitions'], 'key'));
        $this->assertSame(['cup', 'Кубок России', 'group', 'Групповой этап'], [$block['kind'], $block['name'], $block['status'], $block['stage']]);
        $this->assertSame(['Группа A', 1, 3, 'Плей-офф', 'playoff'], [
            $block['group']['name'], $block['group']['place'], $block['group']['teams'], $block['group']['zone_label'], $block['group']['zone'],
        ]);
        $this->assertSame(['Зенит', 'ЦСКА', 'Спартак'], array_column($block['group']['table'], 'team'));
        $this->assertSame([true, false, false], array_column($block['group']['table'], 'is_current'));
        $this->assertSame(['playoff', 'playoff', null], array_column($block['group']['table'], 'zone'));
        $this->assertSame('2026-12-09', $block['next']['date'] ?? null, 'ближайший матч клуба в кубке, не чужой матч группы');
        $this->assertSame('Группа A', collect($props['calendar'])->firstWhere('tournament', 'cup')['stage']);
    }

    public function test_cup_group_points_follow_stage_penalty_rules(): void
    {
        $this->playRounds();
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Group, 'Группы');
        $stage->update(['rules' => ['points' => ['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1]]]);
        $a = StageGroup::create(['stage_id' => $stage->id, 'name' => 'Группа A', 'group_order' => 1]);
        foreach (['zenit', 'spartak', 'cska'] as $slug) {
            StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams[$slug]->id, 'stage_group_id' => $a->id]);
        }

        $this->stageMatch($cup, $stage, 'zenit', 'spartak', '2026-10-20 14:00:00', [1, 1], ['stage_group_id' => $a->id, 'home_pens' => 4, 'away_pens' => 5]);
        $this->stageMatch($cup, $stage, 'cska', 'zenit', '2026-11-03 14:00:00', [0, 0], ['stage_group_id' => $a->id, 'home_pens' => 5, 'away_pens' => 3]);

        $table = collect($this->competitionBlock($this->props(), 'cup')['group']['table'])->keyBy('team');

        $this->assertSame(['Зенит' => 2, 'Спартак' => 2, 'ЦСКА' => 2], $table->map(fn ($r) => $r['pts'])->sortKeys()->all());
        $this->assertSame([0, 2, 0], [$table['Зенит']['wins'], $table['Зенит']['draws'], $table['Зенит']['losses']]);
    }

    public function test_cup_knockout_status_won_lost_and_in_play(): void
    {
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Knockout, 'Плей-офф');
        $r16 = Round::create(['stage_id' => $stage->id, 'number' => 1, 'type' => 'knockout_round', 'code' => 'R16', 'legs' => 1]);
        $qf = Round::create(['stage_id' => $stage->id, 'number' => 2, 'type' => 'knockout_round', 'code' => 'QF', 'legs' => 1]);

        $this->stageMatch($cup, $stage, 'zenit', 'spartak', '2026-09-20 14:00:00', [2, 1], ['round_id' => $r16->id]);
        $props = $this->props();
        $block = $this->competitionBlock($props, 'cup');
        $this->assertSame(['advanced', '1/8 финала', 'Спартак', '2:1', 'won'], [
            $block['status'], $block['stage'], $block['tie']['opponent'], $block['tie']['aggregate'], $block['tie']['result'],
        ]);

        $this->stageMatch($cup, $stage, 'cska', 'zenit', '2026-12-10 14:00:00', null, ['round_id' => $qf->id]);
        $block = $this->competitionBlock($this->props(), 'cup');
        $this->assertSame(['in_play', 'Четвертьфинал', 'ЦСКА', null], [$block['status'], $block['stage'], $block['tie']['opponent'], $block['tie']['aggregate']]);
        $this->assertSame('2026-12-10', $block['next']['date']);

        FootballMatch::where('round_id', $qf->id)->update(['status' => MatchStatus::Finished, 'home_goals' => 1, 'away_goals' => 1, 'home_pens' => 5, 'away_pens' => 3]);
        $block = $this->competitionBlock($this->props(), 'cup');
        $this->assertSame(['eliminated', 'Выбыл · Четвертьфинал', '1:1', '3:5', 'lost'], [
            $block['status'], $block['status_label'], $block['tie']['aggregate'], $block['tie']['penalties'], $block['tie']['result'],
        ]);
    }

    public function test_cup_club_in_playoffs_keeps_group_table_and_shows_knockout_after_it(): void
    {
        $this->playRounds();
        $cup = $this->cupCompetition();
        $group = $this->cupStage($cup, StageType::Group, 'Группы', 1);
        $group->update(['rules' => ['points' => ['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1]]]);
        $knockout = $this->cupStage($cup, StageType::Knockout, 'Play Offs', 2);
        $a = StageGroup::create(['stage_id' => $group->id, 'name' => 'Группа A', 'group_order' => 1]);
        foreach (['cska', 'spartak', 'zenit'] as $slug) {
            StageTeam::create(['stage_id' => $group->id, 'team_id' => $this->teams[$slug]->id, 'stage_group_id' => $a->id]);
        }
        StandingZone::create(['stage_id' => $group->id, 'from_position' => 1, 'to_position' => 1, 'code' => 'playoff', 'label' => 'Плей-офф']);
        $qf = Round::create(['stage_id' => $knockout->id, 'number' => 1, 'type' => 'knockout_round', 'code' => 'QF', 'legs' => 1]);

        $this->stageMatch($cup, $group, 'cska', 'spartak', '2026-09-20 14:00:00', [2, 0], ['stage_group_id' => $a->id]);
        $this->stageMatch($cup, $group, 'zenit', 'cska', '2026-10-03 14:00:00', [1, 1], ['stage_group_id' => $a->id, 'home_pens' => 3, 'away_pens' => 4]);
        $this->stageMatch($cup, $knockout, 'cska', 'spartak', '2026-12-10 14:00:00', null, ['round_id' => $qf->id]);

        $props = $this->props('cska');
        $block = $this->competitionBlock($props, 'cup');

        $this->assertSame('in_play', $block['status']);
        $this->assertSame('В игре · Четвертьфинал', $block['status_label']);
        $this->assertSame(['Групповой этап', 'Группа A', 1, 3, 5, 'playoff'], [
            $block['group']['stage'], $block['group']['name'], $block['group']['place'], $block['group']['teams'], $block['group']['pts'], $block['group']['zone'],
        ]);
        $this->assertSame(['ЦСКА', 'Зенит', 'Спартак'], array_column($block['group']['table'], 'team'));
        $this->assertSame([5, 1, 0], array_column($block['group']['table'], 'pts'));
        $this->assertSame(['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1], $block['group']['rules']);
        $this->assertSame(1, $block['group']['table'][0]['pen_wins']);
        $this->assertSame(1, $block['group']['table'][1]['pen_losses']);
        $this->assertSame(['Play Offs', 'in_play', 'Спартак'], [$block['knockout']['stage'], $block['knockout']['status'], $block['knockout']['tie']['opponent']]);
        $this->assertSame($block['tie'], $block['knockout']['tie']);
    }

    public function test_club_cup_vue_renders_group_then_knockout_with_points_rules(): void
    {
        $vue = file_get_contents(resource_path('js/Components/ClubCupStatus.vue'));

        $this->assertStringContainsString('data-part="group"', $vue);
        $this->assertStringContainsString('data-part="knockout"', $vue);
        $this->assertLessThan(strpos($vue, 'data-part="knockout"'), strpos($vue, 'data-part="group"'), 'группа — выше плей-офф');
        $this->assertStringContainsString('Очки за матч: {{ rulesText }}', $vue);
        $this->assertStringContainsString('победа по пенальти', $vue);
    }

    public function test_two_leg_tie_is_not_decided_until_both_legs_are_played(): void
    {
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Knockout, 'Плей-офф');
        $round = Round::create(['stage_id' => $stage->id, 'number' => 1, 'type' => 'knockout_round', 'code' => 'SF', 'legs' => 2]);

        $this->stageMatch($cup, $stage, 'zenit', 'spartak', '2026-11-04 14:00:00', [0, 1], ['round_id' => $round->id, 'leg' => 1]);
        $block = $this->competitionBlock($this->props(), 'cup');
        $this->assertSame(['in_play', '0:1', null], [$block['status'], $block['tie']['aggregate'], $block['tie']['result']]);

        $this->stageMatch($cup, $stage, 'spartak', 'zenit', '2026-11-11 14:00:00', [0, 3], ['round_id' => $round->id, 'leg' => 2]);
        $block = $this->competitionBlock($this->props(), 'cup');
        $this->assertSame(['advanced', '3:1', 'won'], [$block['status'], $block['tie']['aggregate'], $block['tie']['result']]);
    }

    public function test_cup_final_win_is_marked_as_winner(): void
    {
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Knockout, 'Плей-офф');
        $final = Round::create(['stage_id' => $stage->id, 'number' => 1, 'type' => 'knockout_round', 'code' => 'F', 'legs' => 1]);
        $this->stageMatch($cup, $stage, 'zenit', 'cska', '2026-11-20 14:00:00', [1, 0], ['round_id' => $final->id]);

        $block = $this->competitionBlock($this->props(), 'cup');
        $this->assertSame(['winner', 'Победитель'], [$block['status'], $block['status_label']]);
    }

    public function test_registered_cup_without_matches_is_still_listed(): void
    {
        $this->playRounds();
        $cup = $this->cupCompetition();
        $stage = $this->cupStage($cup, StageType::Group, 'Группы');
        StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams['zenit']->id]);

        $props = $this->props();
        $block = $this->competitionBlock($props, 'cup');

        $this->assertSame(['premier-league', 'cup'], array_column($props['competitions'], 'key'));
        $this->assertSame('group', $block['status']);
        $this->assertNull($block['group']['place']);
        $this->assertSame(['premier-league', 'cup', 'all'], array_column($props['tournaments'], 'key'));
    }

    public function test_friendly_competitions_and_friendly_stages_are_excluded(): void
    {
        $this->playRounds();
        $friendly = $this->cupCompetition('club-friendlies', CompetitionType::Friendly);
        $friendlyStage = $this->cupStage($friendly, StageType::Friendly, 'Товарищеские');
        $this->stageMatch($friendly, $friendlyStage, 'zenit', 'cska', '2026-11-20 14:00:00', [5, 0]);
        $this->stageMatch($friendly, $friendlyStage, 'zenit', 'dynamo', '2026-12-20 14:00:00', null);

        $cup = $this->cupCompetition('cup');
        $cupFriendly = $this->cupStage($cup, StageType::Friendly, 'Спарринг');
        $this->stageMatch($cup, $cupFriendly, 'zenit', 'spartak', '2026-11-21 14:00:00', [4, 0]);

        $props = $this->props();

        $this->assertSame(['premier-league'], array_column($props['competitions'], 'key'));
        $this->assertSame([['key' => 'premier-league', 'name' => 'РПЛ']], $props['tournaments']);
        $this->assertCount(2, $props['results']);
        $this->assertCount(1, $props['calendar']);
        $this->assertSame(['premier-league'], array_column($props['stats']['overall']['rows'], 'key'));
        $this->assertSame(2, $props['stats']['overall']['total']['apps']);
    }

    public function test_competition_without_current_season_activity_is_not_listed(): void
    {
        $this->playRounds();
        $cup = $this->cupCompetition();
        $old = $this->createSeason($cup, '2025-2026', false);
        $oldStage = Stage::create(['season_id' => $old->id, 'name' => 'Плей-офф', 'type' => StageType::Knockout, 'stage_order' => 1]);
        $this->stageMatch($cup, $oldStage, 'zenit', 'cska', '2026-05-20 14:00:00', [1, 0], ['season_id' => $old->id]);

        $this->assertSame(['premier-league'], array_column($this->props()['competitions'], 'key'));
    }

    public function test_last_next_match_is_back_in_its_own_row_not_in_a_side_column(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Clubs/Show.vue'));
        $this->assertStringNotContainsString('miq-club-side', $show);
        $this->assertStringContainsString('<div class="miq-club-comps">', $show);
        $this->assertStringContainsString('<ClubStandingSpot v-if="block.kind === \'league\'"', $show);

        $compsEnd = strpos($show, '</div>', strpos($show, 'miq-club-comps'));
        $lastNext = strpos($show, '<ClubLastNext');
        $filters = strpos($show, 'miq-club-filters');
        $this->assertGreaterThan($compsEnd, $lastNext, 'Последний/Следующий матч — под блоками турниров');
        $this->assertLessThan($filters, $lastNext);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsString('miq-club-side', $css);
        $this->assertStringContainsString('.miq-club-comps', $css);
    }
}
