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

    public function test_match_page_exposes_club_ids_for_home_and_away(): void
    {
        $this->get('/matches/101')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.home_club_id', MockClub::idForName('Зенит'))
                ->where('match.away_club_id', MockClub::idForName('Спартак')));
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
