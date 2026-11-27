<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Enums\MatchEventType;
use App\Enums\MatchSide;
use App\Enums\PlayerPosition;
use App\Enums\StatsPeriod;
use App\Models\FootballMatch;
use App\Models\MatchPrediction;
use App\Models\MatchTeam;
use App\Models\MatchTeamStat;
use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesLeagueData;
use Tests\TestCase;

class TournamentPageTest extends TestCase
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

    public function test_unknown_non_numeric_and_hidden_tournaments_return_404(): void
    {
        $hidden = $this->competition->replicate();
        $hidden->fill(['slug' => 'secret', 'status' => CompetitionStatus::Hidden])->save();

        $this->get('/tournaments/999')->assertNotFound();
        $this->get('/tournaments/rpl')->assertNotFound();
        $this->get("/tournaments/{$hidden->id}")->assertNotFound();
    }

    public function test_page_reads_header_standings_and_club_ids_from_database(): void
    {
        $this->playRounds();

        $zenit = $this->teams['zenit']->id;
        $dynamo = $this->teams['dynamo']->id;

        $this->get("/tournaments/{$this->competition->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tournaments/Show')
                ->where('tournament.id', $this->competition->id)
                ->where('tournament.name', 'РПЛ')
                ->where('tournament.full_name', 'Премьер-лига')
                ->where('tournament.country', 'Россия')
                ->where('tournament.season', '2026/27')
                ->where('tournament.round', 2)
                ->where('tournament.next_round', 3)
                ->where('tournament.rounds_total', 3)
                ->where('tournament.stage', 'Регулярный чемпионат')
                ->where('tournament.summary.teams', 4)
                ->where('tournament.summary.matches', 4)
                ->where('tournament.summary.goals', 10)
                ->where('tournament.summary.goals_per_match', 2.5)
                ->where('tournament.summary.leader.team', 'Динамо')
                ->where('tournament.summary.leader.pts', 4)
                ->where('tournament.summary.gap', 0)
                ->where('zones', [])
                ->has('standings', 4)
                ->where('standings.0.pos', 1)
                ->where('standings.0.team', 'Динамо')
                ->where('standings.0.team_id', $dynamo)
                ->where('standings.0.pts', 4)
                ->where('standings.0.gd', 1)
                ->where('standings.0.form', ['Н', 'В'])
                ->where('standings.1.team', 'ЦСКА')
                ->where('standings.1.pts', 4)
                ->where('standings.1.zone', null)
                ->where('standings.2.team', 'Зенит')
                ->where('standings.2.team_id', $zenit)
                ->where('standings.2.pts', 3)
                ->where('standings.2.form', ['В', 'П']));
    }

    public function test_standings_are_sorted_and_split_by_home_and_away(): void
    {
        $this->playRounds();

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $overall = $props['standingsByContext']['overall'];
        $this->assertSame($props['standings'], $overall);
        $this->assertSame(['Динамо', 'ЦСКА', 'Зенит', 'Спартак'], array_column($overall, 'team'));
        $this->assertSame(range(1, 4), array_column($overall, 'pos'));

        $home = collect($props['standingsByContext']['home'])->keyBy('team');
        $away = collect($props['standingsByContext']['away'])->keyBy('team');
        foreach ($overall as $row) {
            $team = $row['team'];
            $this->assertSame($row['played'], $home[$team]['played'] + $away[$team]['played'], $team);
            $this->assertSame($row['pts'], $home[$team]['pts'] + $away[$team]['pts'], $team);
            $this->assertSame($row['gf'], $home[$team]['gf'] + $away[$team]['gf'], $team);
        }
        $this->assertSame(3, $home['Динамо']['pts']);
        $this->assertSame(1, $away['Динамо']['pts']);
    }

    public function test_team_form_lists_every_played_match_in_order_with_ids(): void
    {
        $this->playRounds();

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $zenit = collect($props['teamForm'])->firstWhere('team', 'Зенит');
        $this->assertSame($this->teams['zenit']->id, $zenit['team_id']);
        $this->assertSame(
            [
                ['round' => 1, 'result' => 'В', 'venue' => 'home', 'opponent' => 'Спартак', 'score' => '2:0', 'date' => '2026-11-07'],
                ['round' => 2, 'result' => 'П', 'venue' => 'away', 'opponent' => 'Динамо', 'score' => '2:3', 'date' => '2026-11-15'],
            ],
            $zenit['matches'],
        );
    }

    public function test_results_and_schedule_are_grouped_by_round(): void
    {
        $matches = $this->playRounds();
        $version = ModelVersion::create(['code' => 'poisson_v1', 'name' => 'Poisson', 'version' => '1']);
        MatchPrediction::create([
            'match_id' => $matches['zenit-cska']->id, 'model_version_id' => $version->id, 'is_current' => true,
            'p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.2, 'generated_at' => now(),
        ]);

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $this->assertSame([2, 1], array_column($props['results'], 'round'));
        $this->assertSame('2026-11-14', $props['results'][0]['date_from']);
        $this->assertSame('2026-11-15', $props['results'][0]['date_to']);
        $this->assertSame(
            ['home' => 'Спартак', 'away' => 'ЦСКА', 'home_goals' => 0, 'away_goals' => 1, 'date' => '2026-11-14', 'time' => '14:00'],
            collect($props['results'][0]['matches'][0])->except('id')->all(),
        );

        $this->assertCount(1, $props['schedule']);
        $next = $props['schedule'][0];
        $this->assertSame(3, $next['round']);
        $this->assertTrue($next['is_next']);
        $this->assertSame(['Зенит', 'Динамо'], array_column($next['matches'], 'home'));
        $this->assertSame([50, 30, 20], [$next['matches'][0]['prob_home'], $next['matches'][0]['prob_draw'], $next['matches'][0]['prob_away']]);
        $this->assertArrayNotHasKey('prob_home', $next['matches'][1], 'без прогноза модели вероятностей нет');
    }

    public function test_top_scorers_assists_and_contribution_come_from_match_events(): void
    {
        $matches = $this->playRounds();
        $sobolev = $this->player('sobolev', 'А. Соболев');
        $glushenkov = $this->player('glushenkov', 'М. Глушенков');
        $keeper = $this->player('keeper', 'В. Вратарь', PlayerPosition::Goalkeeper);

        $this->event($matches['zenit-spartak'], 'zenit', MatchEventType::Goal, $sobolev, $glushenkov, 10);
        $this->event($matches['zenit-spartak'], 'zenit', MatchEventType::PenaltyGoal, $sobolev, null, 60);
        $this->event($matches['dynamo-zenit'], 'zenit', MatchEventType::Goal, $glushenkov, $sobolev, 20);
        $this->event($matches['dynamo-zenit'], 'dynamo', MatchEventType::OwnGoal, $keeper, null, 30);
        $this->event($matches['zenit-cska'], 'zenit', MatchEventType::Goal, $sobolev, null, 5);
        $this->starter($matches['zenit-spartak'], 'zenit', $sobolev);
        $this->starter($matches['dynamo-zenit'], 'zenit', $sobolev);

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $scorers = $props['leaders']['scorers'];
        $this->assertSame(
            ['rank' => 1, 'name' => 'А. Соболев', 'team' => 'Зенит', 'position' => 'FW', 'matches' => 2, 'goals' => 2, 'pens' => 1, 'assists' => 1, 'ga' => 3],
            $scorers[0],
        );
        $this->assertSame(['М. Глушенков', 2, 1, 1, 2], [$scorers[1]['name'], $scorers[1]['ga'], $scorers[1]['goals'], $scorers[1]['assists'], $scorers[1]['rank']]);
        $this->assertCount(2, $scorers, 'автоголы и матчи без счёта не считаются');

        $this->assertSame(['А. Соболев', 'М. Глушенков'], array_column($props['leaders']['assists'], 'name'));
        $this->assertSame([1, 1], array_column($props['leaders']['assists'], 'assists'));

        $contribution = $props['contribution']['overall'];
        $this->assertSame(['А. Соболев', 2, 4, 50], [$contribution[0]['name'], $contribution[0]['goals'], $contribution[0]['team_goals'], $contribution[0]['share']]);
        $this->assertSame([], $props['contribution']['home']);
    }

    public function test_attack_and_defense_follow_the_table(): void
    {
        $this->playRounds();

        $ad = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props']['attackDefense'];

        $this->assertSame(['rank' => 1, 'team' => 'Динамо', 'goals' => 4, 'per_match' => 2.0], $ad['best_attack'][0]);
        $this->assertSame(['rank' => 2, 'team' => 'Зенит', 'goals' => 4, 'per_match' => 2.0], $ad['best_attack'][1]);
        $this->assertSame(['rank' => 1, 'team' => 'Спартак', 'goals' => 0, 'per_match' => 0.0], $ad['worst_attack'][0]);
        $this->assertSame(['rank' => 1, 'team' => 'ЦСКА', 'goals' => 1, 'per_match' => 0.5], $ad['best_defense'][0]);
        $this->assertSame(['rank' => 1, 'team' => 'Динамо', 'goals' => 3, 'per_match' => 1.5], $ad['worst_defense'][0]);
        $this->assertCount(4, $ad['best_attack']);
    }

    /** @param  array<string, mixed>  $metrics */
    private function teamStat(FootballMatch $match, string $team, MatchSide $side, array $metrics, ?float $rating, StatsPeriod $period = StatsPeriod::FullTime): void
    {
        $teamId = $this->teams[$team]->id;
        MatchTeamStat::create(['match_id' => $match->id, 'team_id' => $teamId, 'period' => $period] + $metrics);
        if ($period === StatsPeriod::FullTime) {
            MatchTeam::create(['match_id' => $match->id, 'team_id' => $teamId, 'side' => $side, 'rating' => $rating]);
        }
    }

    public function test_best_teams_are_aggregated_from_match_team_stats_by_context(): void
    {
        $m = $this->playRounds();

        $this->teamStat($m['zenit-spartak'], 'zenit', MatchSide::Home, ['possession_pct' => 60, 'xg' => 2.4, 'shots' => 14, 'pass_accuracy_pct' => 90, 'yellow_cards' => 1], 7.0);
        $this->teamStat($m['zenit-spartak'], 'spartak', MatchSide::Away, ['possession_pct' => 40, 'xg' => 0.6, 'shots' => 4, 'pass_accuracy_pct' => 70, 'yellow_cards' => 3, 'red_cards' => 1], 6.0);
        $this->teamStat($m['dynamo-zenit'], 'dynamo', MatchSide::Home, ['possession_pct' => 50, 'xg' => 1.2, 'shots' => 10, 'pass_accuracy_pct' => 80, 'yellow_cards' => 2], 7.5);
        $this->teamStat($m['dynamo-zenit'], 'zenit', MatchSide::Away, ['possession_pct' => 50, 'xg' => 0.8, 'shots' => 6, 'pass_accuracy_pct' => 80], 6.5);
        $this->teamStat($m['dynamo-zenit'], 'zenit', MatchSide::Away, ['possession_pct' => 99, 'shots' => 99], null, StatsPeriod::FirstHalf);
        $this->teamStat($m['zenit-cska'], 'cska', MatchSide::Away, ['possession_pct' => 70, 'xg' => 3.0, 'shots' => 20, 'pass_accuracy_pct' => 99], 9.0);

        $stats = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props']['teamStats'];
        $col = fn (array $rows, string $key): array => array_column($rows, $key);

        $overall = $stats['overall'];
        $this->assertSame(['possession', 'aggression', 'xg', 'shots', 'passing', 'rating'], array_keys($overall));
        $this->assertSame(['Зенит', 'Динамо', 'Спартак'], $col($overall['possession'], 'team'));
        $this->assertSame([55.0, 50.0, 40.0], $col($overall['possession'], 'possession'));
        $this->assertSame([1, 2, 3], $col($overall['possession'], 'rank'));
        $this->assertSame(['Динамо', 'Зенит', 'Спартак'], $col($overall['shots'], 'team'));
        $this->assertSame([10.0, 10.0, 4.0], $col($overall['shots'], 'shots'));
        $this->assertSame(['Зенит', 'Динамо', 'Спартак'], $col($overall['passing'], 'team'));
        $this->assertSame([85.0, 80.0, 70.0], $col($overall['passing'], 'passing'));
        $this->assertSame(['Динамо', 'Зенит', 'Спартак'], $col($overall['rating'], 'team'));
        $this->assertSame([7.5, 6.75, 6.0], $col($overall['rating'], 'rating'));
        $this->assertSame(
            [['Спартак', 3, 1], ['Динамо', 2, 0], ['Зенит', 1, 0]],
            array_map(fn ($r) => [$r['team'], $r['yellow'], $r['red']], $overall['aggression']),
        );
        $this->assertSame(['Зенит', 'Динамо', 'Спартак'], $col($overall['xg'], 'team'));
        $this->assertSame([1.6, 1.2, 0.6], $col($overall['xg'], 'xg'));

        $this->assertSame(['Зенит', 'Динамо'], $col($stats['home']['possession'], 'team'));
        $this->assertSame([14.0, 10.0], $col($stats['home']['shots'], 'shots'));
        $this->assertSame(['Зенит', 'Спартак'], $col($stats['away']['possession'], 'team'));
        $this->assertSame([6.0, 4.0], $col($stats['away']['shots'], 'shots'));
        $this->assertSame([2.4, 1.2], $col($stats['home']['xg'], 'xg'));
        $this->assertSame([0.8, 0.6], $col($stats['away']['xg'], 'xg'));
    }

    public function test_team_stats_are_empty_without_match_team_stats_rows(): void
    {
        $this->playRounds();

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $this->assertEmpty((array) $props['teamStats']);
    }

    public function test_blocks_without_data_in_database_are_empty_placeholders(): void
    {
        $this->playRounds();

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        foreach (['fastScorers', 'goalkeepers', 'minutes', 'ratings'] as $key) {
            $this->assertSame([], $props['leaders'][$key], $key);
        }
        $this->assertEmpty((array) $props['teamStats']);
        $this->assertSame([], $props['zones']);
    }

    public function test_seasons_list_and_season_query_switch_displayed_data(): void
    {
        $this->playRounds();
        $old = $this->createSeason($this->competition, '2025-2026', false);
        $this->createStage($old);
        $this->match('zenit', 'cska', '2026-05-10 14:00:00', [4, 0], 7, ['season' => $old]);

        $current = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];
        $this->assertSame('2026-2027', $current['seasonKey']);
        $this->assertSame(
            [['key' => '2026-2027', 'label' => '2026/27', 'current' => true], ['key' => '2025-2026', 'label' => '2025/26', 'current' => false]],
            array_map(fn (array $s) => array_intersect_key($s, array_flip(['key', 'label', 'current'])), $current['seasons']),
        );

        $past = $this->get("/tournaments/{$this->competition->id}?season=2025-2026")->viewData('page')['props'];
        $this->assertSame('2025-2026', $past['seasonKey']);
        $this->assertSame(7, $past['tournament']['round']);
        $this->assertSame(1, $past['tournament']['summary']['matches']);
        $this->assertSame(['Зенит', 'ЦСКА'], array_column($past['standings'], 'team'));

        $unknown = $this->get("/tournaments/{$this->competition->id}?season=1999-2000")->viewData('page')['props'];
        $this->assertSame('2026-2027', $unknown['seasonKey']);
    }

    public function test_competition_without_seasons_renders_empty_page(): void
    {
        $this->season->delete();

        $this->get("/tournaments/{$this->competition->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tournament.round', 0)
                ->where('tournament.rounds_total', 0)
                ->where('tournament.summary.leader', null)
                ->where('tournament.summary.gap', null)
                ->where('tournament.summary.goals_per_match', 0)
                ->where('standings', [])
                ->where('results', [])
                ->where('schedule', [])
                ->where('seasons', []));
    }

    public function test_soon_competition_page_is_available(): void
    {
        $soon = $this->competition->replicate();
        $soon->fill(['slug' => 'soon-league', 'status' => CompetitionStatus::Soon])->save();

        $this->get("/tournaments/{$soon->id}")->assertOk();
    }

    public function test_site_header_has_no_tournaments_link(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        $this->assertStringNotContainsString('Турниры', $layout);
        $this->assertStringNotContainsString('tournaments.show', $layout);
    }

    public function test_page_has_section_nav_with_stats_by_default(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Tournaments/Show.vue'));

        foreach (['Статистика', 'Турнирная таблица', 'Форма команд', 'Расписание', 'Результаты'] as $label) {
            $this->assertStringContainsString("label: '$label'", $page);
        }
        $this->assertStringContainsString('DEFAULT_SECTION = SECTIONS[0].id', $page);

        $standings = file_get_contents(resource_path('js/Components/TournamentStandings.vue'));
        $this->assertStringContainsString("route('clubs.show', row.team_id)", $standings);
        $this->assertStringContainsString('overall-label="Все"', $standings);
    }

    public function test_header_component_has_season_select_and_metrics(): void
    {
        $header = file_get_contents(resource_path('js/Components/TournamentHeader.vue'));

        $this->assertStringContainsString('<select', $header);
        $this->assertStringContainsString('v-for="season in seasons"', $header);
        $this->assertStringContainsString('router.get', $header);
        foreach (['Тур', 'Голов', 'Лидер', 'Отрыв от 2-го'] as $label) {
            $this->assertStringContainsString("<dt>$label</dt>", $header);
        }
    }
}
