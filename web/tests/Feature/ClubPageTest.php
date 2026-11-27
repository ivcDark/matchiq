<?php

namespace Tests\Feature;

use App\Support\MockClub;
use App\Support\MockTournament;
use Tests\TestCase;

class ClubPageTest extends TestCase
{
    public function test_club_page_renders_with_summary_props(): void
    {
        $this->get('/clubs/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clubs/Show')
                ->where('club.id', 1)
                ->where('club.name', 'Зенит')
                ->where('standing.position', 1)
                ->where('standing.teams', 16)
                ->has('standing.neighbors', 5)
                ->where('cup.name', 'Кубок России')
                ->has('cup.stage')
                ->has('cup.group')
                ->has('cup.table', 4)
                ->has('tournaments', 3)
                ->where('tournaments.2.key', 'all')
                ->where('tournaments.2.name', 'Все матчи')
                ->has('stats.overall.rows', 2)
                ->has('stats.home.total')
                ->has('stats.away.total')
                ->has('goals.rpl.overall.markets')
                ->has('goals.cup.home.markets')
                ->has('players.rpl.overall')
                ->has('players.rpl.home')
                ->has('players.rpl.away')
                ->has('players.cup.overall')
                ->has('goals.all.overall.markets')
                ->has('goals.all.away.markets')
                ->has('players.all.overall')
                ->has('players.all.home')
                ->has('players.all.away'));
    }

    public function test_club_page_has_last_and_next_match_across_all_tournaments(): void
    {
        $this->get('/clubs/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('last_match', fn ($m) => $m
                    ->whereType('opponent', 'string')
                    ->whereType('gf', 'integer')
                    ->whereType('ga', 'integer')
                    ->whereType('date', 'string')
                    ->whereType('time', 'string')
                    ->whereType('result', 'string')
                    ->missing('match_id')
                    ->whereType('opponent_id', 'integer')
                    ->whereType('home', 'string')
                    ->whereType('away', 'string')
                    ->whereType('home_score', 'integer')
                    ->whereType('away_score', 'integer')
                    ->has('tournament')
                    ->has('tournament_name')
                    ->has('stage')
                    ->has('venue'))
                ->has('next_match', fn ($m) => $m
                    ->whereType('opponent', 'string')
                    ->whereType('date', 'string')
                    ->whereType('time', 'string')
                    ->missing('match_id')
                    ->whereType('opponent_id', 'integer')
                    ->whereType('home', 'string')
                    ->whereType('away', 'string')
                    ->has('tournament')
                    ->has('tournament_name')
                    ->has('stage')
                    ->has('venue')
                    ->missing('home_score')
                    ->missing('away_score')
                    ->missing('gf')));
    }

    public function test_last_and_next_match_come_from_rpl_and_cup_mocks(): void
    {
        $tournaments = [];
        foreach (MockClub::TEAMS as $id => $team) {
            $data = MockClub::find($id);
            $last = $data['last_match'];
            $next = $data['next_match'];

            $this->assertContains($last['tournament'], ['rpl', 'cup'], $team);
            $this->assertContains($next['tournament'], ['rpl', 'cup'], $team);
            $this->assertContains($last['result'], ['В', 'Н', 'П'], $team);
            $this->assertContains($last['venue'], ['home', 'away'], $team);
            $this->assertLessThan($next['date'].' '.$next['time'], $last['date'].' '.$last['time'], "$team: последний раньше следующего");
            $tournaments[$last['tournament']] = true;

            $rpl = collect(MockTournament::teamFixtures($team));
            $lastRpl = $rpl->whereNotNull('gf')->last();
            $nextRpl = $rpl->whereNull('gf')->first();
            $this->assertSame(MockTournament::ROUNDS_PLAYED, $lastRpl['round'], $team);
            $this->assertSame(MockTournament::ROUNDS_PLAYED + 1, $nextRpl['round'], $team);

            if ($last['tournament'] === 'rpl') {
                $this->assertSame($lastRpl['opponent'], $last['opponent'], $team);
                $this->assertSame([$lastRpl['gf'], $lastRpl['ga']], [$last['gf'], $last['ga']], $team);
                $this->assertSame($lastRpl['date'], $last['date'], $team);
            } else {
                $this->assertGreaterThan($lastRpl['date'], $last['date'], $team);
                $this->assertSame($data['cup']['group'], mb_substr($last['stage'], 7, 1), $team);
            }
            if ($next['tournament'] === 'rpl') {
                $this->assertSame($nextRpl['opponent'], $next['opponent'], $team);
                $this->assertSame($nextRpl['date'], $next['date'], $team);
            }
            $this->assertLessThanOrEqual($nextRpl['date'], $next['date'], $team);
        }

        $this->assertArrayHasKey('rpl', $tournaments);
        $this->assertArrayHasKey('cup', $tournaments, 'в моках у части клубов последний матч — кубковый');
    }

    public function test_last_and_next_match_ignore_page_filters_and_are_rendered_in_vue(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Clubs/Show.vue'));
        $this->assertStringContainsString('<ClubLastNext :last="last_match" :next="next_match"', $show);

        $component = file_get_contents(resource_path('js/Components/ClubLastNext.vue'));
        $this->assertStringNotContainsString('matches.show', $component);
        $this->assertStringNotContainsString('Link', $component);
        $this->assertStringNotContainsString('match_id', $component);
        $this->assertStringNotContainsString('context', $component);
        $this->assertStringNotContainsString('tournament.value', $component);
    }

    public function test_club_nav_has_only_summary_calendar_results_without_statistics_tab(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Clubs/Show.vue'));
        $this->assertStringContainsString("{ id: 'summary', label: 'Сводка' }", $show);
        $this->assertStringContainsString("{ id: 'calendar', label: 'Календарь' }", $show);
        $this->assertStringContainsString("{ id: 'results', label: 'Результаты' }", $show);
        $this->assertStringNotContainsString("id: 'stats'", $show);
        $this->assertStringNotContainsString("label: 'Статистика'", $show);
        $this->assertStringNotContainsString('PLACEHOLDERS', $show);
        $this->assertStringContainsString('<ClubFixtures title="Календарь" mode="calendar" :matches="calendar"', $show);
        $this->assertStringContainsString('<ClubFixtures title="Результаты" mode="results" :matches="results"', $show);

        $fixtures = file_get_contents(resource_path('js/Components/ClubFixtures.vue'));
        $this->assertStringNotContainsString('Link', $fixtures);
        $this->assertStringNotContainsString('<a ', $fixtures);
        $this->assertStringNotContainsString('matches.show', $fixtures);
        $this->assertStringContainsString('{{ m.home }}', $fixtures);
        $this->assertStringContainsString('{{ m.away }}', $fixtures);
        $this->assertStringContainsString('{{ m.home_score }} : {{ m.away_score }}', $fixtures);
        $this->assertStringContainsString('– : –', $fixtures);
    }

    public function test_calendar_and_results_props_split_rpl_and_cup_fixtures(): void
    {
        $this->get('/clubs/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('calendar', 17, fn ($m) => $m
                    ->whereType('opponent', 'string')
                    ->whereType('date', 'string')
                    ->whereType('time', 'string')
                    ->whereType('opponent_id', 'integer')
                    ->whereType('home', 'string')
                    ->whereType('away', 'string')
                    ->has('tournament')
                    ->has('tournament_name')
                    ->has('stage')
                    ->has('venue')
                    ->missing('home_score')
                    ->missing('away_score')
                    ->missing('gf')
                    ->missing('result')
                    ->missing('match_id'))
                ->has('results', 19, fn ($m) => $m
                    ->whereType('opponent', 'string')
                    ->whereType('gf', 'integer')
                    ->whereType('ga', 'integer')
                    ->whereType('result', 'string')
                    ->whereType('date', 'string')
                    ->whereType('opponent_id', 'integer')
                    ->whereType('home', 'string')
                    ->whereType('away', 'string')
                    ->whereType('home_score', 'integer')
                    ->whereType('away_score', 'integer')
                    ->has('tournament')
                    ->has('tournament_name')
                    ->has('stage')
                    ->has('venue')
                    ->has('time')
                    ->missing('match_id')));
    }

    public function test_calendar_is_ascending_upcoming_and_results_descending_played_for_every_club(): void
    {
        foreach (MockClub::TEAMS as $id => $team) {
            $data = MockClub::find($id);
            $calendar = $data['calendar'];
            $results = $data['results'];

            $rpl = collect(MockTournament::teamFixtures($team));
            $this->assertCount($rpl->whereNull('gf')->count() + 2, $calendar, "$team: календарь = 15 туров РПЛ + 2 тура Кубка");
            $this->assertCount($rpl->whereNotNull('gf')->count() + 4, $results, "$team: результаты = 15 туров РПЛ + 4 тура Кубка");

            $when = static fn (array $m): string => $m['date'].' '.$m['time'];
            $dates = array_map($when, $calendar);
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates, "$team: календарь по возрастанию даты");
            $dates = array_map($when, $results);
            $sorted = $dates;
            rsort($sorted);
            $this->assertSame($sorted, $dates, "$team: результаты по убыванию даты");
            $this->assertLessThan($when($calendar[0]), $when($results[0]), "$team: сыгранные раньше предстоящих");

            $this->assertEquals($data['next_match'], $calendar[0], $team);
            $this->assertEquals($data['last_match'], $results[0], $team);
            $this->assertSame(['cup', 'rpl'], collect([...$calendar, ...$results])->pluck('tournament')->unique()->sort()->values()->all(), $team);

            foreach ($results as $m) {
                $this->assertSame(['В', 'Н', 'П'][1 - ($m['gf'] <=> $m['ga'])], $m['result'], $team);
                $this->assertSame($m['venue'] === 'home' ? $team : $m['opponent'], $m['home'], $team);
                $this->assertSame($m['venue'] === 'home' ? $m['opponent'] : $team, $m['away'], $team);
                $this->assertSame($m['venue'] === 'home' ? $m['gf'] : $m['ga'], $m['home_score'], $team);
                $this->assertSame($m['venue'] === 'home' ? $m['ga'] : $m['gf'], $m['away_score'], $team);
            }
            foreach ($calendar as $m) {
                $this->assertContains($m['venue'], ['home', 'away'], $team);
                $this->assertSame($m['venue'] === 'home' ? $team : $m['opponent'], $m['home'], $team);
                $this->assertSame($m['venue'] === 'home' ? $m['opponent'] : $team, $m['away'], $team);
                $this->assertArrayNotHasKey('home_score', $m, $team);
                $this->assertArrayNotHasKey('gf', $m, $team);
            }
        }
    }

    public function test_goals_block_has_opened_scoring_market_instead_of_scored(): void
    {
        $this->get('/clubs/1')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $market = collect($props['goals']['rpl']['overall']['markets'])->firstWhere('key', 'scored');

                $this->assertSame('Открыли счёт', $market['title']);
                $this->assertSame('Да', $market['left_label']);
                $this->assertSame('Нет', $market['right_label']);
                $this->assertSame($props['goals']['rpl']['overall']['matches'], $market['left'] + $market['right']);
                $this->assertSame(100, $market['left_pct'] + $market['right_pct']);

                $matches = MockTournament::teamMatches('Зенит');
                $scoredAtLeastOnce = count(array_filter($matches, fn (array $m) => $m['gf'] > 0));
                $keptCleanSheetAndScored = count(array_filter($matches, fn (array $m) => $m['gf'] > 0 && $m['ga'] === 0));
                $this->assertLessThanOrEqual($scoredAtLeastOnce, $market['left']);
                $this->assertGreaterThanOrEqual($keptCleanSheetAndScored, $market['left']);
            });

        $first = MockClub::find(1)['goals']['rpl']['overall']['markets'];
        $this->assertSame($first, MockClub::find(1)['goals']['rpl']['overall']['markets']);
    }

    public function test_every_standings_team_has_a_working_club_page(): void
    {
        $standings = MockTournament::find(1)['standings'];

        $this->assertCount(16, $standings);
        foreach ($standings as $row) {
            $this->assertNotNull($row['team_id'], $row['team']);
            $this->get("/clubs/{$row['team_id']}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('club.name', $row['team'])
                    ->where('standing.position', $row['pos'])
                    ->where('standing.pts', $row['pts']));
        }
    }

    public function test_unknown_or_non_numeric_club_returns_404(): void
    {
        $this->get('/clubs/999')->assertNotFound();
        $this->get('/clubs/zenit')->assertNotFound();
    }

    public function test_club_links_are_wired_in_vue(): void
    {
        $standings = file_get_contents(resource_path('js/Components/TournamentStandings.vue'));
        $this->assertStringContainsString("route('clubs.show', row.team_id)", $standings);

        $match = file_get_contents(resource_path('js/Pages/Matches/Show.vue'));
        $this->assertStringContainsString("route('clubs.show', match.home_club_id)", $match);
        $this->assertStringContainsString("route('clubs.show', match.away_club_id)", $match);
    }

    public function test_standing_spot_matches_tournament_table(): void
    {
        $rows = collect(MockTournament::find(1)['standings']);

        foreach (MockClub::TEAMS as $id => $team) {
            $row = $rows->firstWhere('team', $team);
            $spot = MockClub::find($id)['standing'];

            $this->assertSame($row['pos'], $spot['position'], $team);
            $this->assertSame($row['pts'], $spot['pts'], $team);
            $this->assertCount(5, $spot['neighbors'], $team);
        }
    }

    public function test_cup_stage_is_consistent(): void
    {
        $groups = [];
        foreach (MockClub::TEAMS as $id => $team) {
            $cup = MockClub::find($id)['cup'];

            $this->assertContains($cup['group'], ['A', 'B', 'C', 'D']);
            $this->assertCount(4, $cup['table']);
            $groups[$cup['group']][] = $team;
        }

        $this->assertCount(4, $groups);
        foreach ($groups as $teams) {
            $this->assertCount(4, $teams);
        }
    }

    public function test_context_filters_change_numbers_and_add_up(): void
    {
        foreach (MockClub::TEAMS as $id => $team) {
            $stats = MockClub::find($id)['stats'];

            foreach ([0, 1] as $i) {
                foreach (['apps', 'goals'] as $field) {
                    $this->assertSame(
                        $stats['overall']['rows'][$i][$field],
                        $stats['home']['rows'][$i][$field] + $stats['away']['rows'][$i][$field],
                        "$team #$i $field: дома + в гостях = всего",
                    );
                }
            }
        }

        $zenit = MockClub::find(1);
        $this->assertNotEquals($zenit['stats']['overall']['rows'][0], $zenit['stats']['home']['rows'][0]);
        $this->assertNotEquals($zenit['players']['rpl']['overall'], $zenit['players']['rpl']['home']);
        $this->assertNotEquals($zenit['players']['rpl']['home'], $zenit['players']['rpl']['away']);
    }

    public function test_players_goals_match_team_goals(): void
    {
        foreach (MockClub::TEAMS as $id => $team) {
            $data = MockClub::find($id);

            foreach (['overall', 'home', 'away'] as $context) {
                $this->assertSame(
                    $data['stats'][$context]['rows'][0]['goals'],
                    array_sum(array_column($data['players']['rpl'][$context], 'goals')),
                    "$team $context",
                );
            }
        }
    }

    public function test_all_matches_option_aggregates_rpl_and_cup(): void
    {
        foreach (MockClub::TEAMS as $id => $team) {
            $data = MockClub::find($id);

            foreach (['overall', 'home', 'away'] as $context) {
                $total = $data['stats'][$context]['total'];
                $all = $data['goals']['all'][$context];
                $rpl = $data['goals']['rpl'][$context];
                $cup = $data['goals']['cup'][$context];

                $this->assertSame($rpl['matches'] + $cup['matches'], $all['matches'], "$team $context матчи");
                $this->assertSame($total['apps'], $all['matches'], "$team $context матчи = итог таблицы");
                $this->assertSame($total['goals'], $all['scored'], "$team $context забито = итог таблицы");
                $this->assertSame($total['conceded'], $all['conceded'], "$team $context пропущено = итог таблицы");

                foreach ($all['markets'] as $i => $market) {
                    $this->assertSame(
                        $rpl['markets'][$i]['left'] + $cup['markets'][$i]['left'],
                        $market['left'],
                        "$team $context {$market['key']}",
                    );
                    $this->assertSame($all['matches'], $market['left'] + $market['right']);
                }

                $this->assertSame($total['goals'], array_sum(array_column($data['players']['all'][$context], 'goals')), "$team $context голы игроков");
                foreach (['goals', 'assists', 'yellow', 'red', 'minutes', 'apps'] as $field) {
                    $this->assertGreaterThanOrEqual(
                        array_sum(array_column($data['players']['rpl'][$context], $field))
                            + array_sum(array_column($data['players']['cup'][$context], $field)),
                        array_sum(array_column($data['players']['all'][$context], $field)),
                        "$team $context игроки: $field",
                    );
                }
            }

            $this->assertSame(
                $data['goals']['all']['overall']['matches'],
                $data['goals']['all']['home']['matches'] + $data['goals']['all']['away']['matches'],
            );
        }
    }

    public function test_tournament_switch_has_all_matches_without_duplicate_venue_hint(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Clubs/Show.vue'));
        $this->assertStringContainsString("overall: ''", $show);
        $this->assertStringNotContainsString("'все матчи'", $show);
        $this->assertSame(3, substr_count($show, '<ClubTournamentSwitch v-model="tournament"'));
    }
}
