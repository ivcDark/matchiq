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
                ->has('match'));
    }

    public function test_leaderboard_renders(): void
    {
        $this->get('/leaderboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Leaderboard/Index'));
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
