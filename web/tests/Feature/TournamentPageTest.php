<?php

namespace Tests\Feature;

use App\Support\MockTournament;
use Tests\TestCase;

class TournamentPageTest extends TestCase
{
    public function test_rpl_tournament_page_renders(): void
    {
        $this->get('/tournaments/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tournaments/Show')
                ->where('tournament.id', 1)
                ->where('tournament.name', 'РПЛ')
                ->has('tournament.season')
                ->has('tournament.round')
                ->has('tournament.rounds_total')
                ->has('tournament.stage')
                ->where('seasonKey', '2026-27')
                ->where('tournament.season_key', '2026-27')
                ->has('seasons', 3)
                ->where('seasons.0.key', '2026-27')
                ->where('seasons.0.label', '2026/27')
                ->where('seasons.0.current', true)
                ->has('seasons.0.summary.goals')
                ->has('seasons.1.summary.leader.team')
                ->has('tournament.summary.gap')
                ->has('zones', 5)
                ->has('standings', 16)
                ->has('standingsByContext.overall', 16)
                ->has('standingsByContext.home', 16)
                ->has('standingsByContext.away', 16)
                ->has('teamForm', 16)
                ->has('teamForm.0.matches', 15)
                ->has('schedule', 15)
                ->where('schedule.0.round', 16)
                ->where('schedule.0.is_next', true)
                ->has('schedule.0.matches', 8)
                ->has('schedule.0.matches.0.prob_home')
                ->has('schedule.0.matches.0.top_market')
                ->has('results', 15)
                ->where('results.0.round', 15)
                ->has('results.0.matches', 8)
                ->where('tournament.next_round', 16)
                ->has('leaders.scorers')
                ->has('leaders.assists')
                ->has('leaders.fastScorers')
                ->has('leaders.goalkeepers')
                ->has('leaders.minutes')
                ->has('leaders.ratings')
                ->has('teamStats.overall.possession', 5)
                ->has('teamStats.home.aggression', 5)
                ->has('teamStats.away.rating', 5)
                ->has('attackDefense.best_attack', 5)
                ->has('attackDefense.best_defense', 5)
                ->has('attackDefense.worst_attack', 5)
                ->has('attackDefense.worst_defense', 5)
                ->has('contribution.overall', 10)
                ->has('contribution.home', 10)
                ->has('contribution.away', 10)
                ->has('thresholds'));
    }

    public function test_unknown_or_non_numeric_tournament_returns_404(): void
    {
        $this->get('/tournaments/999')->assertNotFound();
        $this->get('/tournaments/rpl')->assertNotFound();
    }

    public function test_match_page_links_to_tournament(): void
    {
        $this->get('/matches/101')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('match.tournament_id', 1));
    }

    public function test_home_links_only_to_leagues_with_tournament_page(): void
    {
        $this->get('/?league=rpl')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $leagues = collect($props['leagues'])->pluck('tournament_id', 'id');

                $this->assertSame(1, $leagues['rpl']);
                $this->assertNull($leagues['fnl']);
                $this->assertNull($leagues['ucl']);
                $this->assertSame(1, $props['widgets']['league']['tournament_id']);
            });

        $this->get('/?league=fnl')
            ->assertInertia(fn ($page) => $page->where('widgets.league.tournament_id', null));
    }

    public function test_site_header_has_no_tournaments_link(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        $this->assertStringNotContainsString('Турниры', $layout);
        $this->assertStringNotContainsString('tournaments.show', $layout);
    }

    public function test_standings_are_consistent_and_sorted(): void
    {
        $rows = MockTournament::find(1)['standings'];

        $this->assertSame(range(1, 16), array_column($rows, 'pos'));
        $this->assertSame(array_column($rows, 'pts'), collect(array_column($rows, 'pts'))->sortDesc()->values()->all());
        $this->assertSame(array_sum(array_column($rows, 'wins')), array_sum(array_column($rows, 'losses')));
        $this->assertSame(array_sum(array_column($rows, 'gf')), array_sum(array_column($rows, 'ga')));

        foreach ($rows as $row) {
            $this->assertSame($row['wins'] + $row['draws'] + $row['losses'], $row['played']);
            $this->assertSame($row['wins'] * 3 + $row['draws'], $row['pts']);
            $this->assertSame($row['gf'] - $row['ga'], $row['gd']);
            $this->assertCount(5, $row['form']);
            $this->assertContainsOnlyString($row['form']);
        }
    }

    public function test_standings_zones_follow_positions(): void
    {
        $zones = collect(MockTournament::find(1)['standings'])->pluck('zone', 'pos');

        $this->assertSame('ucl', $zones[1]);
        $this->assertSame('europa', $zones[3]);
        $this->assertNull($zones[8]);
        $this->assertSame('playoff', $zones[13]);
        $this->assertSame('relegation', $zones[16]);
    }

    public function test_top_lists_are_sorted_and_respect_thresholds(): void
    {
        $data = MockTournament::find(1);
        $leaders = $data['leaders'];

        // Таблицы бомбардиров / скорострельных / вратарей получают весь список (сортировка на клиенте, показ топ-10).
        $this->assertGreaterThanOrEqual(10, count($leaders['scorers']));
        $this->assertGreaterThanOrEqual(10, count($leaders['fastScorers']));
        $this->assertGreaterThanOrEqual(10, count($leaders['goalkeepers']));

        foreach ($leaders['scorers'] as $p) {
            $this->assertArrayHasKey('goals', $p);
            $this->assertArrayHasKey('pens', $p);
            $this->assertArrayHasKey('assists', $p);
            $this->assertSame($p['goals'] + $p['assists'], $p['ga']);
            $this->assertLessThanOrEqual($p['goals'], $p['pens']);
        }
        $ga = array_column($leaders['scorers'], 'ga');
        $sortedGa = $ga;
        rsort($sortedGa);
        $this->assertSame($sortedGa, $ga, 'по умолчанию бомбардиры отсортированы по Г+П');

        foreach (['assists', 'minutes', 'ratings'] as $key) {
            $this->assertGreaterThanOrEqual(10, count($leaders[$key]), $key);
        }
        $assists = array_column($leaders['assists'], 'assists');
        $sortedAssists = $assists;
        rsort($sortedAssists);
        $this->assertSame($sortedAssists, $assists);

        $goals90 = array_column($leaders['fastScorers'], 'goals_per_90');
        $sorted90 = $goals90;
        rsort($sorted90);
        $this->assertSame($sorted90, $goals90, 'скорострельные по умолчанию — по голам на 90 минут');

        foreach ($leaders['fastScorers'] as $p) {
            $this->assertGreaterThanOrEqual(MockTournament::MIN_MINUTES_SCORING, $p['minutes']);
            $this->assertEqualsWithDelta($p['goals'] * 90 / $p['minutes'], $p['goals_per_90'], 0.006);
        }
        // Нападающий с 3 голами за 220 минут слишком мало играл — в список не попадает.
        $this->assertNotContains('Тихонов П.', array_column($leaders['fastScorers'], 'name'));

        foreach ($leaders['goalkeepers'] as $p) {
            $this->assertGreaterThanOrEqual(MockTournament::MIN_MINUTES_GOALKEEPER, $p['minutes']);
            $this->assertGreaterThan(0, $p['clean_sheets']);
            $this->assertSame((int) round($p['minutes'] / $p['clean_sheets']), $p['minutes_per_clean_sheet']);
            $this->assertEqualsWithDelta($p['clean_sheets'] * 90 / $p['minutes'], $p['clean_sheets_per_90'], 0.006);
        }
        $per90 = array_column($leaders['goalkeepers'], 'clean_sheets_per_90');
        $sortedPer90 = $per90;
        rsort($sortedPer90);
        $this->assertSame($sortedPer90, $per90, 'вратари по умолчанию — по сухим на 90 минут');
        $this->assertNotContains('Зорин А.', array_column($leaders['goalkeepers'], 'name'));

        $ratings = array_column($leaders['ratings'], 'rating');
        $sortedRatings = $ratings;
        rsort($sortedRatings);
        $this->assertSame($sortedRatings, $ratings);
        foreach ($leaders['ratings'] as $p) {
            $this->assertGreaterThanOrEqual(MockTournament::MIN_MATCHES_RATING, $p['matches']);
        }
    }

    public function test_team_stats_cover_three_contexts_and_are_sorted(): void
    {
        $stats = MockTournament::find(1)['teamStats'];
        $this->assertSame(['overall', 'home', 'away'], array_keys($stats));

        $tables = ['possession', 'aggression', 'aerial', 'shots', 'passing', 'rating'];
        $fields = ['possession' => 'possession', 'aerial' => 'aerial', 'shots' => 'shots', 'passing' => 'passing', 'rating' => 'rating'];

        foreach ($stats as $context => $byTable) {
            $this->assertSame($tables, array_keys($byTable), $context);
            foreach ($byTable as $key => $rows) {
                $this->assertSame(range(1, 5), array_column($rows, 'rank'), "$context/$key");
                $this->assertCount(5, array_unique(array_column($rows, 'team')));
            }
            foreach ($fields as $key => $field) {
                $values = array_column($byTable[$key], $field);
                $sorted = $values;
                rsort($sorted);
                $this->assertSame($sorted, $values, "$context/$key отсортирован по убыванию");
            }
            $yellow = array_column($byTable['aggression'], 'yellow');
            $sortedYellow = $yellow;
            rsort($sortedYellow);
            $this->assertSame($sortedYellow, $yellow);
        }

        foreach (['possession', 'aerial', 'passing'] as $key) {
            foreach ($stats as $byTable) {
                foreach ($byTable[$key] as $row) {
                    $this->assertLessThanOrEqual(100, $row[$key]);
                }
            }
        }
    }

    public function test_team_cards_split_into_home_and_away(): void
    {
        $stats = MockTournament::find(1)['teamStats'];
        $sum = static function (string $context) use ($stats): array {
            return collect($stats[$context]['aggression'])->mapWithKeys(fn ($r) => [$r['team'] => $r['yellow'] + $r['red']])->all();
        };

        foreach ($sum('home') as $team => $cards) {
            if (isset($sum('overall')[$team], $sum('away')[$team])) {
                $this->assertSame($sum('overall')[$team], $cards + $sum('away')[$team]);
            }
        }
    }

    public function test_contribution_rows_are_consistent_per_context(): void
    {
        $contribution = MockTournament::find(1)['contribution'];
        $teamGoals = collect(MockTournament::find(1)['standings'])->pluck('gf', 'team');

        foreach ($contribution as $context => $rows) {
            $this->assertSame(range(1, 10), array_column($rows, 'rank'), $context);
            $goals = array_column($rows, 'goals');
            $sorted = $goals;
            rsort($sorted);
            $this->assertSame($sorted, $goals, "$context: по убыванию голов");

            foreach ($rows as $row) {
                $this->assertGreaterThan(0, $row['goals']);
                $this->assertLessThanOrEqual($row['team_goals'], $row['goals']);
                $this->assertSame((int) round($row['goals'] / $row['team_goals'] * 100), $row['share']);
                if ($context === 'overall') {
                    $this->assertSame($teamGoals[$row['team']], $row['team_goals']);
                }
            }
        }

        $overall = collect($contribution['overall'])->keyBy('name');
        foreach (['home', 'away'] as $context) {
            foreach ($contribution[$context] as $row) {
                $this->assertLessThanOrEqual($overall[$row['name']]['goals'] ?? PHP_INT_MAX, $row['goals']);
            }
        }
    }

    public function test_attack_defense_tables_follow_standings(): void
    {
        $data = MockTournament::find(1);
        $standings = collect($data['standings'])->keyBy('team');
        $ad = $data['attackDefense'];

        $this->assertSame(['best_attack', 'best_defense', 'worst_attack', 'worst_defense'], array_keys($ad));

        foreach ($ad as $key => $rows) {
            $this->assertSame(range(1, 5), array_column($rows, 'rank'), $key);
            $field = str_contains($key, 'attack') ? 'gf' : 'ga';
            foreach ($rows as $row) {
                $team = $standings[$row['team']];
                $this->assertSame($team[$field], $row['goals'], $key);
                $this->assertEqualsWithDelta($team[$field] / $team['played'], $row['per_match'], 0.006);
            }
        }

        $goals = static fn (string $key) => array_column($ad[$key], 'goals');
        $this->assertSame(max(array_column($data['standings'], 'gf')), $goals('best_attack')[0]);
        $this->assertSame(min(array_column($data['standings'], 'ga')), $goals('best_defense')[0]);
        $this->assertSame(min(array_column($data['standings'], 'gf')), $goals('worst_attack')[0]);
        $this->assertSame(max(array_column($data['standings'], 'ga')), $goals('worst_defense')[0]);

        $sorted = $goals('best_attack');
        rsort($sorted);
        $this->assertSame($sorted, $goals('best_attack'));
        $sorted = $goals('best_defense');
        sort($sorted);
        $this->assertSame($sorted, $goals('best_defense'), 'у защиты меньше пропущенных — лучше');
    }

    public function test_player_tables_carry_position(): void
    {
        $leaders = MockTournament::find(1)['leaders'];

        foreach ($leaders as $key => $rows) {
            foreach ($rows as $row) {
                $this->assertArrayHasKey($row['position'], MockTournament::POSITIONS, $key);
            }
        }
        foreach ($leaders['goalkeepers'] as $row) {
            $this->assertSame('GK', $row['position']);
        }
        $positions = array_unique(array_column($leaders['ratings'], 'position'));
        sort($positions);
        $this->assertSame(['DF', 'FW', 'GK', 'MF'], $positions);
    }

    public function test_championship_season_label_follows_date(): void
    {
        $this->assertSame('2026/27', MockTournament::find(1, now()->setDate(2026, 11, 24))['tournament']['season']);
        $this->assertSame('2026/27', MockTournament::find(1, now()->setDate(2027, 3, 1))['tournament']['season']);
        $this->assertSame('2027/28', MockTournament::find(1, now()->setDate(2027, 7, 1))['tournament']['season']);
    }

    public function test_standings_are_built_from_matches(): void
    {
        $zenit = collect(MockTournament::find(1)['standings'])->firstWhere('team', 'Зенит');

        $this->assertSame(1, $zenit['pos']);
        $this->assertSame([15, 10, 4, 1, 30, 10, 34], [
            $zenit['played'], $zenit['wins'], $zenit['draws'], $zenit['losses'], $zenit['gf'], $zenit['ga'], $zenit['pts'],
        ]);
    }

    public function test_home_and_away_tables_split_the_overall_table(): void
    {
        $tables = MockTournament::find(1)['standingsByContext'];
        $sum = static fn (string $context, string $field) => collect($tables[$context])->pluck($field, 'team');

        foreach (['played', 'wins', 'draws', 'losses', 'gf', 'ga', 'pts'] as $field) {
            foreach ($sum('overall', $field) as $team => $total) {
                $this->assertSame($total, $sum('home', $field)[$team] + $sum('away', $field)[$team], "$team/$field");
            }
        }

        foreach (['home', 'away'] as $context) {
            $rows = $tables[$context];
            $this->assertSame(range(1, 16), array_column($rows, 'pos'));
            $pts = array_column($rows, 'pts');
            $sorted = $pts;
            rsort($sorted);
            $this->assertSame($sorted, $pts, "$context: по убыванию очков");
            $this->assertContains(array_sum(array_column($rows, 'played')), [120]);
            $this->assertSame([null], array_unique(array_column($rows, 'zone')), 'зоны только в общей таблице');
            foreach ($rows as $row) {
                $this->assertContains($row['played'], [7, 8]);
                $this->assertLessThanOrEqual(5, count($row['form']));
            }
        }

        $this->assertNotSame(
            array_column($tables['home'], 'team'),
            array_column($tables['away'], 'team'),
            'таблицы хозяев и гостей различаются',
        );
        $this->assertSame(MockTournament::find(1)['standings'], $tables['overall']);
    }

    public function test_team_form_covers_every_played_match_in_order(): void
    {
        $data = MockTournament::find(1);
        $standings = collect($data['standings'])->keyBy('team');

        $this->assertSame(array_column($data['standings'], 'team'), array_column($data['teamForm'], 'team'));

        foreach ($data['teamForm'] as $team) {
            $row = $standings[$team['team']];
            $this->assertCount($row['played'], $team['matches']);
            $this->assertSame(range(1, 15), array_column($team['matches'], 'round'));

            $letters = array_count_values(array_column($team['matches'], 'result'));
            $this->assertSame($row['wins'], $letters['В'] ?? 0, $team['team']);
            $this->assertSame($row['draws'], $letters['Н'] ?? 0, $team['team']);
            $this->assertSame($row['losses'], $letters['П'] ?? 0, $team['team']);
            $this->assertSame(array_slice(array_column($team['matches'], 'result'), -5), $row['form']);
        }
    }

    public function test_every_round_has_each_team_once(): void
    {
        $data = MockTournament::find(1);

        foreach ([...$data['schedule'], ...$data['results']] as $round) {
            $teams = [];
            foreach ($round['matches'] as $m) {
                array_push($teams, $m['home'], $m['away']);
            }
            $this->assertCount(16, array_unique($teams), 'тур '.$round['round']);
            $this->assertCount(8, $round['matches']);
        }
        $this->assertSame(range(16, 30), array_column($data['schedule'], 'round'));
        $this->assertSame(range(15, 1), array_column($data['results'], 'round'));
    }

    public function test_results_are_played_and_before_schedule(): void
    {
        $data = MockTournament::find(1);

        foreach ($data['results'] as $round) {
            foreach ($round['matches'] as $m) {
                $this->assertIsInt($m['home_goals']);
                $this->assertIsInt($m['away_goals']);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $m['date']);
                $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $m['time']);
            }
        }

        $lastResult = max(array_column($data['results'][0]['matches'], 'date'));
        $firstFixture = min(array_column($data['schedule'][0]['matches'], 'date'));
        $this->assertLessThan($firstFixture, $lastResult);
    }

    public function test_only_next_round_has_probabilities_summing_to_100(): void
    {
        $schedule = MockTournament::find(1)['schedule'];

        foreach ($schedule[0]['matches'] as $m) {
            $this->assertSame(100, $m['prob_home'] + $m['prob_draw'] + $m['prob_away']);
            $this->assertGreaterThanOrEqual(0, $m['prob_draw']);
            $this->assertNotEmpty($m['top_market']['label']);
            $this->assertGreaterThan(0, $m['top_market']['prob']);
            $this->assertLessThanOrEqual(100, $m['top_market']['prob']);
            $this->assertArrayNotHasKey('home_goals', $m);
        }

        foreach (array_slice($schedule, 1) as $round) {
            $this->assertFalse($round['is_next']);
            foreach ($round['matches'] as $m) {
                $this->assertArrayNotHasKey('prob_home', $m);
            }
        }
    }

    public function test_second_round_mirrors_first_with_swapped_venues(): void
    {
        $data = MockTournament::find(1);
        $first = collect($data['results'])->flatMap(fn ($r) => $r['matches'])->map(fn ($m) => $m['home'].'|'.$m['away'])->sort()->values();
        $second = collect($data['schedule'])->flatMap(fn ($r) => $r['matches'])->map(fn ($m) => $m['away'].'|'.$m['home'])->sort()->values();

        $this->assertCount(120, $first);
        $this->assertSame($first->all(), $second->all());
    }

    public function test_page_has_section_nav_with_stats_by_default(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Tournaments/Show.vue'));

        foreach (['Статистика', 'Турнирная таблица', 'Форма команд', 'Расписание', 'Результаты'] as $label) {
            $this->assertStringContainsString("label: '$label'", $page);
        }
        $this->assertStringContainsString("DEFAULT_SECTION = SECTIONS[0].id", $page);
        $this->assertStringContainsString("{ id: 'stats', label: 'Статистика' }", $page);

        $standings = file_get_contents(resource_path('js/Components/TournamentStandings.vue'));
        $this->assertStringContainsString('overall-label="Все"', $standings);
    }

    public function test_seasons_are_listed_with_distinct_summaries(): void
    {
        $seasons = MockTournament::find(1, now()->setDate(2026, 11, 24))['seasons'];

        $this->assertSame(['2026-27', '2025-26', '2024-25'], array_column($seasons, 'key'));
        $this->assertSame(['2026/27', '2025/26', '2024/25'], array_column($seasons, 'label'));
        $this->assertSame([true, false, false], array_column($seasons, 'current'));
        $this->assertSame([15, 12, 9], array_column($seasons, 'round'));
        $this->assertCount(3, array_unique(array_column(array_column($seasons, 'summary'), 'goals')));

        foreach ($seasons as $season) {
            $summary = $season['summary'];
            $this->assertSame(16, $summary['teams']);
            $this->assertSame($season['round'] * 8, $summary['matches']);
            $this->assertEqualsWithDelta($summary['goals'] / $summary['matches'], $summary['goals_per_match'], 0.006);
            $this->assertGreaterThanOrEqual(0, $summary['gap']);
        }
    }

    public function test_current_season_summary_matches_standings(): void
    {
        $data = MockTournament::find(1);
        $summary = $data['tournament']['summary'];

        $this->assertSame($data['standings'][0]['team'], $summary['leader']['team']);
        $this->assertSame($data['standings'][0]['pts'], $summary['leader']['pts']);
        $this->assertSame($data['standings'][0]['pts'] - $data['standings'][1]['pts'], $summary['gap']);
        $this->assertSame(array_sum(array_column($data['standings'], 'gf')), $summary['goals']);
        $this->assertSame($data['seasons'][0]['summary'], $summary);
    }

    public function test_season_query_switches_displayed_data(): void
    {
        $current = MockTournament::find(1);
        $earlier = MockTournament::find(1, null, '2025-26');

        $this->assertSame('2025-26', $earlier['seasonKey']);
        $this->assertSame('2025/26', $earlier['tournament']['season']);
        $this->assertSame(12, $earlier['tournament']['round']);
        $this->assertSame(13, $earlier['tournament']['next_round']);
        $this->assertCount(12, $earlier['results']);
        $this->assertSame(13, $earlier['schedule'][0]['round']);
        $this->assertCount(18, $earlier['schedule']);
        $this->assertSame(96, array_sum(array_column($earlier['standings'], 'played')) / 2);
        $this->assertNotSame($current['standings'], $earlier['standings']);
        $this->assertSame($earlier['seasons'][1]['summary'], $earlier['tournament']['summary']);

        foreach ($earlier['standings'] as $row) {
            $this->assertSame(12, $row['played']);
        }
        foreach (['scorers', 'assists', 'fastScorers', 'goalkeepers', 'minutes', 'ratings'] as $key) {
            $this->assertNotEmpty($earlier['leaders'][$key], $key);
        }
    }

    public function test_season_query_is_applied_by_controller(): void
    {
        $this->get('/tournaments/1?season=2024-25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seasonKey', '2024-25')
                ->where('tournament.season', '2024/25')
                ->where('tournament.round', 9)
                ->has('results', 9)
                ->has('seasons', 3));

        $this->get('/tournaments/1?season=1999-00')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('seasonKey', '2026-27')->where('tournament.round', 15));

        $this->get('/tournaments/1?season[]=x')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('seasonKey', '2026-27'));
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

        $page = file_get_contents(resource_path('js/Pages/Tournaments/Show.vue'));
        $this->assertStringContainsString('<TournamentHeader', $page);
    }
}
