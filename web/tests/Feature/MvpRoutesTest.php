<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_index_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->has('leagues')
                ->has('matches')
                ->has('widgets')
                ->has('widgets.standings')
                ->has('widgets.scorers')
                ->has('widgets.hotScorers')
                ->has('widgets.news')
                ->where('selectedLeagues', ['all'])
                ->where('period', 'today')
                ->where('date', now()->toDateString()));
    }

    public function test_matches_index_accepts_league_filter(): void
    {
        $this->get('/?league=rpl&period=today')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('selectedLeagues', ['rpl'])
                ->where('period', 'today')
                ->where('date', now()->toDateString()));
    }

    public function test_matches_index_accepts_multi_league_filter(): void
    {
        $this->get('/?league=rpl,ucl&period=tomorrow')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('selectedLeagues', ['rpl', 'ucl'])
                ->where('period', 'tomorrow')
                ->where('date', now()->addDay()->toDateString()));
    }

    public function test_matches_index_accepts_rpl_fnl_multi_select(): void
    {
        $this->get('/?league=rpl,fnl&period=yesterday')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('selectedLeagues', ['rpl', 'fnl'])
                ->where('leagues.1.id', 'fnl')
                ->where('leagues.1.country', 'Россия')
                ->where('leagues.1.status', 'live')
                ->where('leagues.0.sort_order', 10)
                ->where('leagues.1.sort_order', 20)
                ->where('period', 'yesterday')
                ->where('date', now()->subDay()->toDateString()));
    }

    public function test_matches_index_accepts_absolute_date_filter(): void
    {
        $date = now()->addDays(5)->toDateString();

        $this->get('/?date='.$date)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('period', null)
                ->where('date', $date));
    }

    public function test_matches_index_date_normalizes_to_named_period(): void
    {
        $today = now()->toDateString();

        $this->get('/?date='.$today)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('period', 'today')
                ->where('date', $today));
    }

    public function test_matches_index_accepts_repeated_league_params(): void
    {
        $this->get('/?league[]=rpl&league[]=ucl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('selectedLeagues', ['rpl', 'ucl']));
    }

    public function test_matches_show_renders(): void
    {
        $this->get('/matches/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->has('match')
                ->has('markets')
                ->has('preview')
                ->has('comparison')
                ->has('comparison.match')
                ->has('comparison.first_half')
                ->has('comparison.second_half')
                ->has('homeAway')
                ->has('homeAway.tournament')
                ->has('homeAway.last5')
                ->has('homeAway.last10')
                ->has('standings')
                ->has('scorers')
                ->has('bestPlayers')
                ->has('bestPlayers.home')
                ->has('bestPlayers.away')
                ->has('absences')
                ->has('goalMinutes')
                ->has('goalMinutes.scored')
                ->has('goalMinutes.conceded')
                ->has('goalMinutes.scale')
                ->has('formBlocks')
                ->has('forecast')
                ->has('insights')
                ->has('trends')
                ->has('goalProfile')
                ->has('refereeStats')
                ->has('refereeStats.samples')
                ->has('refereeStats.tabs')
                ->has('comments')
                ->has('match.weather'));
    }

    public function test_matches_show_zenit_spartak_demo_detail(): void
    {
        $this->get('/matches/101?league=rpl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->where('match.id', 101)
                ->where('match.home', 'Зенит')
                ->where('match.away', 'Спартак')
                ->where('match.league_short', 'РПЛ')
                ->where('returnLeague', 'rpl')
                ->has('markets', 25)
                ->has('markets.0.outcomes')
                ->where('markets.0.name', 'Исход матча')
                ->where('markets.0.category', 'results')
                ->where('markets.0.outcomes.0.prob', 42)
                ->where('markets.0.outcomes.1.prob', 27)
                ->where('markets.0.outcomes.2.prob', 31)
                ->where('markets.4.name', 'Тотал 1.5')
                ->where('markets.4.category', 'totals')
                ->where('markets.4.outcomes.0.prob', 78)
                ->where('markets.4.outcomes.1.prob', 22)
                ->where('markets.16.category', 'corners')
                ->where('markets.20.category', 'yellows')
                ->where('markets.23.category', 'reds')
                ->has('preview')
                ->has('formBlocks', 5)
                ->where('formBlocks.0.id', 'h2h')
                ->where('formBlocks.0.focus', 'Зенит')
                ->has('formBlocks.0.matches', 5)
                ->where('formBlocks.0.matches.0.competition', 'РПЛ')
                ->has('formBlocks.0.matches.0.ht_home')
                ->has('formBlocks.0.matches.0.ht_away')
                ->has('scorers.home', 5)
                ->has('scorers.away', 5)
                ->where('scorers.home.0.player', 'М. Глушенков')
                ->where('scorers.home.0.goals', 11)
                ->where('scorers.home.0.assists', 4)
                ->where('scorers.home.0.pens', 2)
                ->where('scorers.home.0.ga', 15)
                ->where('bestPlayers.home.name', 'Глушенков М.')
                ->where('bestPlayers.home.position', 'ПЗ')
                ->where('bestPlayers.home.number', 67)
                ->where('bestPlayers.home.photo_url', null)
                ->has('bestPlayers.home.stats.matches')
                ->has('bestPlayers.home.stats.goals')
                ->has('bestPlayers.home.stats.assists')
                ->has('bestPlayers.home.stats.yellow')
                ->where('bestPlayers.away.name', 'Барко Т.')
                ->has('standings')
                ->where('standings.0.form', ['В', 'В', 'Н', 'П', 'В'])
                ->where('match.form_home', ['В', 'В', 'Н', 'В', 'П'])
                ->where('goalMinutes.scale', 12)
                ->has('goalMinutes.scored', 6)
                ->has('goalMinutes.conceded', 6)
                ->where('goalMinutes.scored.0.band', '0–15')
                ->where('goalMinutes.conceded.5.band', '75–90')
                ->where('match.referee', 'В. Мешков')
                ->where('refereeStats.name', 'В. Мешков')
                ->where('refereeStats.place', 'Россия · Санкт-Петербург')
                ->where('refereeStats.season', '2025/26')
                ->has('refereeStats.tabs', 3)
                ->where('refereeStats.tabs.0.id', 'all')
                ->where('refereeStats.tabs.1.id', 'rpl')
                ->where('refereeStats.tabs.2.id', 'cup')
                ->has('refereeStats.samples.all.matches')
                ->has('refereeStats.samples.rpl.matches')
                ->where('refereeStats.samples.cup.ypm', null)
                ->where('userPickEventId', null));
    }

    public function test_leaderboard_renders(): void
    {
        $this->get('/leaderboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leaderboard/Index')
                ->where('period', 'season')
                ->has('periods', 4)
                ->where('periods.0.label', 'Неделя')
                ->where('periods.3.id', 'all_time')
                ->has('entries', 12)
                ->where('entries.0.rank', 1)
                ->has('entries.0.name')
                ->has('entries.0.picks')
                ->has('entries.0.correct')
                ->has('entries.0.accuracy')
                ->has('entries.0.points')
                ->has('entries.0.streak')
                ->where('entries.0.is_me', false)
                ->where('currentUserRank', null));
    }

    public function test_leaderboard_accepts_period_and_falls_back_on_unknown(): void
    {
        $this->get('/leaderboard?period=week')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leaderboard/Index')
                ->where('period', 'week'));

        $this->get('/leaderboard?period=bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', 'season'));
    }

    public function test_leaderboard_entries_are_sorted_by_points(): void
    {
        foreach (['week', 'month', 'season', 'all_time'] as $period) {
            $this->get('/leaderboard?period='.$period)
                ->assertInertia(function ($page) {
                    $points = array_column($page->toArray()['props']['entries'], 'points');
                    $sorted = $points;
                    rsort($sorted);

                    $this->assertSame($sorted, $points);
                });
        }
    }

    public function test_leaderboard_highlights_current_user(): void
    {
        $user = User::factory()->create(['name' => 'Тестовый Игрок']);

        $this->actingAs($user)
            ->get('/leaderboard')
            ->assertOk()
            ->assertInertia(function ($page) {
                $entries = collect($page->toArray()['props']['entries']);
                $me = $entries->where('is_me', true);

                $this->assertCount(1, $me);
                $this->assertSame('Тестовый Игрок', $me->first()['name']);
                $this->assertSame(
                    $me->first()['rank'],
                    $page->toArray()['props']['currentUserRank'],
                );
            });
    }

    public function test_me_requires_auth(): void
    {
        $this->get('/me')->assertRedirect(route('login'));
    }

    public function test_me_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/me')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Profile/Show'));
    }
}
