<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchStatus;
use App\Enums\MatchEventType;
use App\Enums\MatchSide;
use App\Enums\PlayerPosition;
use App\Enums\StageType;
use App\Enums\StatsPeriod;
use App\Models\FootballMatch;
use App\Models\MatchPlayerStat;
use App\Models\MatchPrediction;
use App\Models\MatchTeam;
use App\Models\MatchTeamStat;
use App\Models\ModelVersion;
use App\Models\Competition;
use App\Models\Player;
use App\Models\Stage;
use App\Models\StageGroup;
use App\Models\StageTeam;
use App\Models\Team;
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

    private function playerStat(FootballMatch $match, string $team, Player $player, int $minutes, int $goals = 0, ?float $rating = null, array $extra = []): void
    {
        MatchPlayerStat::create([
            'match_id' => $match->id, 'team_id' => $this->teams[$team]->id, 'player_id' => $player->id,
            'minutes' => $minutes, 'goals' => $goals, 'rating' => $rating, 'extra' => $extra === [] ? null : $extra,
        ]);
    }

    public function test_player_leaders_come_from_match_player_stats_without_minimum_thresholds(): void
    {
        $zenit = $this->match('zenit', 'spartak', '2026-10-01 14:00:00', [2, 0], 1);
        $scorer = $this->player('scorer', 'А. Соболев');
        $bench = $this->player('bench', 'К. Запасной');
        $keeper = $this->player('keeper', 'И. Вратарь', PlayerPosition::Goalkeeper);
        $this->match('zenit', 'spartak', '2026-12-01 14:00:00', null, 3);

        for ($i = 0; $i < 10; $i++) {
            $m = $this->match('zenit', 'cska', '2026-10-1'.$i.' 14:00:00', [1, $i % 2], 2 + $i);
            $this->playerStat($m, 'zenit', $scorer, 90, $i < 4 ? 1 : 0, 7.0 + $i / 10);
            $this->playerStat($m, 'zenit', $keeper, 90, 0, 6.5, ['goals_conceded' => $i % 2]);
            $this->playerStat($m, 'zenit', $bench, 5, 0, 6.0);
        }
        $this->playerStat($zenit, 'zenit', $scorer, 90, 0, null);

        $leaders = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props']['leaders'];

        $this->assertSame(
            ['rank' => 1, 'name' => 'А. Соболев', 'team' => 'Зенит', 'position' => 'FW', 'minutes' => 990, 'goals' => 4, 'goals_per_90' => 0.36, 'minutes_per_goal' => 248],
            $leaders['fastScorers'][0],
        );
        $this->assertCount(1, $leaders['fastScorers'], 'у запасного и вратаря мало минут или нет голов');

        $this->assertSame(
            ['rank' => 1, 'name' => 'И. Вратарь', 'team' => 'Зенит', 'position' => 'GK', 'minutes' => 900, 'clean_sheets' => 5, 'clean_sheets_per_90' => 0.5, 'minutes_per_clean_sheet' => 180],
            $leaders['goalkeepers'][0],
        );

        $this->assertSame(['А. Соболев', 'И. Вратарь', 'К. Запасной'], array_column($leaders['minutes'], 'name'));
        $this->assertSame([11, 10, 10], array_column($leaders['minutes'], 'matches'));
        $this->assertSame([990, 900, 50], array_column($leaders['minutes'], 'minutes'));

        $this->assertSame(['А. Соболев', 'И. Вратарь', 'К. Запасной'], array_column($leaders['ratings'], 'name'));
        $this->assertSame([7.45, 6.5, 6.0], array_column($leaders['ratings'], 'rating'));
        $this->assertSame([10, 10, 10], array_column($leaders['ratings'], 'matches'));
    }

    public function test_early_season_leaders_are_not_empty_and_use_compact_english_names(): void
    {
        $m = $this->match('zenit', 'spartak', '2026-10-01 14:00:00', [2, 0], 1);
        $scorer = $this->player('sobolev', 'Aleksandr Sobolev');
        $keeper = $this->player('keeper', 'Matvey Safonov', PlayerPosition::Goalkeeper);
        $this->playerStat($m, 'zenit', $scorer, 90, 1, 7.8);
        $this->playerStat($m, 'zenit', $keeper, 90, 0, 7.1, ['goals_conceded' => 0]);

        $leaders = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props']['leaders'];

        $this->assertSame(['A. Sobolev'], array_column($leaders['fastScorers'], 'name'));
        $this->assertSame(['M. Safonov'], array_column($leaders['goalkeepers'], 'name'));
        $this->assertSame(['A. Sobolev', 'M. Safonov'], array_column($leaders['minutes'], 'name'));
        $this->assertSame(['A. Sobolev', 'M. Safonov'], array_column($leaders['ratings'], 'name'));
        $this->assertSame([1, 1], array_column($leaders['ratings'], 'matches'));
    }

    public function test_player_leaders_ignore_unfinished_matches(): void
    {
        $this->playRounds();
        $scheduled = $this->match('zenit', 'spartak', '2026-12-10 14:00:00', null, 4);
        $player = $this->player('ghost', 'Н. Призрак');
        $this->playerStat($scheduled, 'zenit', $player, 90, 1, 8.0);

        $leaders = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props']['leaders'];

        $this->assertSame([], $leaders['minutes']);
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

    public function test_schedule_and_results_rows_link_to_match_page(): void
    {
        $matches = $this->playRounds();
        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $this->assertSame($matches['spartak-cska']->id ?? null, $props['results'][0]['matches'][0]['id'] ?? null);
        foreach ([...$props['results'], ...$props['schedule']] as $block) {
            foreach ($block['matches'] as $m) {
                $this->assertIsInt($m['id']);
                $this->get(route('matches.show', $m['id'], false))->assertOk();
            }
        }

        foreach (['TournamentResults', 'TournamentSchedule'] as $component) {
            $source = file_get_contents(resource_path("js/Components/{$component}.vue"));
            $this->assertStringContainsString("route('matches.show', m.id)", $source);
        }
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

    /** @return array{cup: Competition, stage: Stage, groups: array<string, StageGroup>} */
    private function russianCupWithGroups(): array
    {
        $cup = Competition::create([
            'country_id' => $this->country->id, 'slug' => 'russian-cup', 'name' => 'Russian Cup', 'name_i18n' => ['ru' => 'Кубок России'],
            'short_name' => 'Cup', 'short_name_i18n' => ['ru' => 'Кубок России'],
            'type' => CompetitionType::Cup, 'status' => CompetitionStatus::Live, 'sort_order' => 20,
        ]);
        $season = $this->createSeason($cup, '2026-2027', true);
        $stage = Stage::create([
            'season_id' => $season->id, 'name' => 'Групповой этап', 'type' => StageType::Group, 'stage_order' => 1,
            'has_standings' => true, 'rules' => config('matchiq.stage_rules.russian-cup'),
        ]);

        $groups = [];
        foreach (['A', 'B', 'C', 'D'] as $i => $letter) {
            $groups[$letter] = StageGroup::create(['stage_id' => $stage->id, 'name' => "Группа $letter", 'group_order' => $i + 1]);
        }
        foreach (['zenit', 'spartak', 'cska', 'dynamo'] as $slug) {
            StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams[$slug]->id, 'stage_group_id' => $groups['C']->id]);
        }
        foreach (['A' => ['a1', 'a2'], 'B' => ['b1', 'b2'], 'D' => ['d1']] as $letter => $slugs) {
            foreach ($slugs as $slug) {
                $this->teams[$slug] = Team::create(['country_id' => $this->country->id, 'slug' => $slug, 'name' => strtoupper($slug), 'name_i18n' => ['ru' => strtoupper($slug)]]);
                StageTeam::create(['stage_id' => $stage->id, 'team_id' => $this->teams[$slug]->id, 'stage_group_id' => $groups[$letter]->id]);
            }
        }

        return ['cup' => $cup, 'stage' => $stage, 'groups' => $groups];
    }

    private function cupMatch(array $cup, string $group, string $home, string $away, string $kickoff, array $score, ?array $pens = null): FootballMatch
    {
        return FootballMatch::create([
            'competition_id' => $cup['cup']->id, 'season_id' => $cup['stage']->season_id, 'stage_id' => $cup['stage']->id,
            'stage_group_id' => $cup['groups'][$group]->id,
            'home_team_id' => $this->teams[$home]->id, 'away_team_id' => $this->teams[$away]->id,
            'kickoff_at' => $kickoff, 'status' => MatchStatus::Finished,
            'home_goals' => $score[0], 'away_goals' => $score[1],
            'home_pens' => $pens[0] ?? null, 'away_pens' => $pens[1] ?? null,
        ]);
    }

    public function test_russian_cup_shows_group_tables_with_penalty_points(): void
    {
        $cup = $this->russianCupWithGroups();
        $this->cupMatch($cup, 'C', 'zenit', 'spartak', '2026-09-01 18:00', [1, 0]);
        $this->cupMatch($cup, 'C', 'cska', 'dynamo', '2026-09-01 20:00', [2, 2], [4, 3]);
        $this->cupMatch($cup, 'C', 'zenit', 'cska', '2026-09-15 18:00', [1, 1], [3, 5]);
        $this->cupMatch($cup, 'C', 'spartak', 'dynamo', '2026-09-15 20:00', [2, 1]);
        $this->cupMatch($cup, 'C', 'zenit', 'dynamo', '2026-09-29 18:00', [0, 0], [4, 2]);
        $this->cupMatch($cup, 'C', 'cska', 'spartak', '2026-09-29 20:00', [1, 0]);
        $this->cupMatch($cup, 'A', 'a1', 'a2', '2026-09-01 16:00', [3, 0]);
        $this->cupMatch($cup, 'B', 'b1', 'b2', '2026-09-01 14:00', [1, 1], [5, 4]);

        $knockout = Stage::create(['season_id' => $cup['stage']->season_id, 'name' => '1/8 финала', 'type' => StageType::Knockout, 'stage_order' => 2]);
        FootballMatch::create([
            'competition_id' => $cup['cup']->id, 'season_id' => $knockout->season_id, 'stage_id' => $knockout->id,
            'home_team_id' => $this->teams['zenit']->id, 'away_team_id' => $this->teams['spartak']->id,
            'kickoff_at' => '2026-10-20 18:00', 'status' => MatchStatus::Finished, 'home_goals' => 5, 'away_goals' => 0,
        ]);

        $props = $this->get("/tournaments/{$cup['cup']->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tournaments/Show')->where('standingsMode', 'groups'))
            ->viewData('page')['props'];

        $this->assertSame('Групповой этап', $props['tournament']['stage']);
        $this->assertSame(['Группа A', 'Группа B', 'Группа C', 'Группа D'], array_column($props['groupStage']['groups'], 'name'));
        $this->assertSame(
            ['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1],
            $props['groupStage']['rules'],
        );
        $this->assertNull($props['tournament']['summary']['leader']);
        $this->assertNull($props['tournament']['summary']['gap']);

        $byGroup = collect($props['standingsByContext']['overall'])->groupBy('group_id');
        $groupC = $byGroup[$cup['groups']['C']->id]->keyBy('team');

        $this->assertSame(['ЦСКА', 'Зенит', 'Спартак', 'Динамо'], $byGroup[$cup['groups']['C']->id]->pluck('team')->all());
        $this->assertSame(['ЦСКА' => 7, 'Зенит' => 6, 'Спартак' => 3, 'Динамо' => 2], $groupC->map->pts->all(), 'не плоская 3-1-0: Зенит 5, ЦСКА 5');
        $this->assertSame([1, 1, 1], [$groupC['Зенит']['wins'], $groupC['Зенит']['pen_wins'], $groupC['Зенит']['pen_losses']]);
        $this->assertSame([0, 0, 3], [$groupC['Зенит']['draws'], $groupC['Динамо']['draws'], $groupC['Зенит']['played']]);
        $this->assertSame([2, 3], [$groupC['Спартак']['gf'], $groupC['Спартак']['ga']], 'плей-офф 5:0 не попадает в таблицу группы');

        $this->assertSame(['A1' => 3, 'A2' => 0], $byGroup[$cup['groups']['A']->id]->pluck('pts', 'team')->all());
        $this->assertSame(['B1' => 2, 'B2' => 1], $byGroup[$cup['groups']['B']->id]->pluck('pts', 'team')->all());
        $this->assertSame(['D1' => 0], $byGroup[$cup['groups']['D']->id]->pluck('pts', 'team')->all());
        $this->assertSame(0, $byGroup[$cup['groups']['D']->id]->first()['played']);

        $home = collect($props['standingsByContext']['home'])->where('group_id', $cup['groups']['C']->id)->keyBy('team');
        $this->assertSame([6, 5], [$home['Зенит']['pts'], $home['ЦСКА']['pts']]);
    }

    public function test_league_page_keeps_flat_table_without_group_payload(): void
    {
        $this->playRounds();

        $props = $this->get("/tournaments/{$this->competition->id}")->viewData('page')['props'];

        $this->assertSame('league', $props['standingsMode']);
        $this->assertNull($props['groupStage']);
        $this->assertArrayNotHasKey('group_id', $props['standings'][0]);
        $this->assertArrayNotHasKey('pen_wins', $props['standings'][0]);
    }

    public function test_cup_without_group_stage_keeps_flat_mode(): void
    {
        $cup = Competition::create([
            'country_id' => $this->country->id, 'slug' => 'knockout-cup', 'name' => 'Cup', 'type' => CompetitionType::Cup,
            'status' => CompetitionStatus::Live, 'sort_order' => 30,
        ]);
        $this->createSeason($cup, '2026-2027', true);

        $this->get("/tournaments/{$cup->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('standingsMode', 'league')->where('groupStage', null));
    }

    public function test_group_standings_component_is_wired_for_cup_only(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Tournaments/Show.vue'));
        $this->assertStringContainsString("standingsMode === 'groups' && groupStage", $page);
        $this->assertStringContainsString('<TournamentGroupStandings', $page);

        $groups = file_get_contents(resource_path('js/Components/TournamentGroupStandings.vue'));
        $this->assertStringContainsString('v-for="group in groups"', $groups);
        $this->assertStringContainsString('title="Победы по пенальти"', $groups);
        $this->assertStringNotContainsString('Очки за матч', $groups);
        $this->assertStringContainsString('props.stage.rules', $groups);
    }
}
