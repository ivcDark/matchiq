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
                ->has('zones', 5)
                ->has('standings', 16)
                ->has('leaders.scorers', 10)
                ->has('leaders.assists', 10)
                ->has('leaders.fastScorers', 10)
                ->has('leaders.goalkeepers', 10)
                ->has('leaders.minutes', 10)
                ->has('leaders.ratings', 10)
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

        $this->assertSame(range(1, 10), array_column($leaders['scorers'], 'rank'));
        $this->assertSame(
            array_column($leaders['scorers'], 'goals'),
            collect($leaders['scorers'])->pluck('goals')->sortDesc()->values()->all(),
        );

        $perGoal = array_column($leaders['fastScorers'], 'minutes_per_goal');
        $sorted = $perGoal;
        sort($sorted);
        $this->assertSame($sorted, $perGoal);

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
        }
        $this->assertNotContains('Зорин А.', array_column($leaders['goalkeepers'], 'name'));

        $ratings = array_column($leaders['ratings'], 'rating');
        $sortedRatings = $ratings;
        rsort($sortedRatings);
        $this->assertSame($sortedRatings, $ratings);
        foreach ($leaders['ratings'] as $p) {
            $this->assertGreaterThanOrEqual(MockTournament::MIN_MATCHES_RATING, $p['matches']);
        }
    }

    public function test_championship_season_label_follows_date(): void
    {
        $this->assertSame('2026/27', MockTournament::find(1, now()->setDate(2026, 11, 24))['tournament']['season']);
        $this->assertSame('2026/27', MockTournament::find(1, now()->setDate(2027, 3, 1))['tournament']['season']);
        $this->assertSame('2027/28', MockTournament::find(1, now()->setDate(2027, 7, 1))['tournament']['season']);
    }
}
