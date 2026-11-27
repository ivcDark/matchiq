<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RatingSeason;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_leaderboard_exposes_current_rating_season(): void
    {
        Carbon::setTestNow('2026-11-24 12:00:00');

        $this->get('/leaderboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('season.label', '2026/27')
                ->where('season.title', 'Сезон 2026/27')
                ->where('season.range', '1 авг 2026 — 31 июл 2027')
                ->where('season.hint', RatingSeason::HINT)
                ->where('periods.2.id', 'season')
                ->where('periods.2.caption', '2026/27')
                ->where('periods.2.range', '1 авг 2026 — 31 июл 2027')
                ->where('periods.2.hint', RatingSeason::HINT)
                ->missing('periods.0.caption'));
    }

    public function test_leaderboard_season_switches_on_august_first(): void
    {
        Carbon::setTestNow('2027-07-31 23:30:00');
        $this->get('/leaderboard')->assertInertia(fn ($page) => $page->where('season.label', '2026/27'));

        Carbon::setTestNow('2027-08-01 00:30:00');
        $this->get('/leaderboard')->assertInertia(fn ($page) => $page
            ->where('season.label', '2027/28')
            ->where('season.range', '1 авг 2027 — 31 июл 2028'));
    }

    public function test_user_profile_exposes_rating_season(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');

        $this->get('/users/1000000')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('season.title', 'Сезон 2025/26')
                ->where('season.range', '1 авг 2025 — 31 июл 2026')
                ->where('ratings.2.id', 'season')
                ->where('ratings.2.caption', '2025/26')
                ->where('ratings.0.caption', null));
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

    public function test_user_profile_renders_for_mock_player(): void
    {
        $this->get('/users/1000000')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Show')
                ->where('profile.id', 1000000)
                ->where('profile.nick', 'Бомбардир_77')
                ->where('profile.is_me', false)
                ->where('profile.rank', 1)
                ->has('profile.registered_label')
                ->has('ratings', 4)
                ->where('ratings.0.id', 'week')
                ->where('ratings.3.id', 'all_time')
                ->where('ratings.3.rank', 1)
                ->has('ratings.0.points')
                ->has('championships')
                ->where('championships.0.name', 'РПЛ')
                ->has('bestPick.match')
                ->has('bestPick.event')
                ->has('bestPick.odds')
                ->has('bestPick.probability')
                ->has('bestPick.points')
                ->where('bestPick.points', fn ($points) => $points === 100 - $page->toArray()['props']['bestPick']['probability'])
                ->has('frequentEvents', 5)
                ->where('frequentEvents.0.label', 'Исходы')
                ->where('frequentEvents.1.label', 'Тоталы')
                ->where('frequentEvents.2.label', 'Фора')
                ->where('frequentEvents.3.label', 'Угловые')
                ->where('frequentEvents.4.label', 'ЖК')
                ->has('frequentEvents.0.items.0.count')
                ->has('frequentEvents.0.items.0.accuracy'));
    }

    public function test_user_profile_rank_matches_leaderboard(): void
    {
        $leaderboard = $this->get('/leaderboard?period=season')->viewData('page')['props']['entries'];
        $second = $leaderboard[1];

        $this->get('/users/'.$second['id'])
            ->assertInertia(function ($page) use ($second) {
                $season = collect($page->toArray()['props']['ratings'])->firstWhere('id', 'season');

                $this->assertSame($second['rank'], $season['rank']);
                $this->assertSame($second['points'], $season['points']);
            });
    }

    public function test_user_profile_championship_and_event_totals_are_consistent(): void
    {
        $this->get('/users/1000002')
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $allTimePicks = collect($props['ratings'])->firstWhere('id', 'all_time')['picks'];

                $this->assertSame($allTimePicks, array_sum(array_column($props['championships'], 'picks')));
                $this->assertSame($allTimePicks, array_sum(array_column($props['frequentEvents'], 'total')));
            });
    }

    public function test_user_profile_is_available_for_registered_user(): void
    {
        $user = User::factory()->create(['name' => 'Новичок Вася']);

        $this->actingAs($user)
            ->get('/users/'.$user->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Show')
                ->where('profile.id', $user->id)
                ->where('profile.nick', 'Новичок Вася')
                ->where('profile.is_me', true)
                ->where('profile.registered_at', $user->created_at->toDateString()));

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get('/users/'.$user->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profile.is_me', false));
    }

    public function test_user_profile_returns_404_for_unknown_id(): void
    {
        $this->get('/users/999')->assertNotFound();
        $this->get('/users/1000012')->assertNotFound();
        $this->get('/users/'.rawurlencode('Бомбардир_77'))->assertNotFound();
    }

    public function test_leaderboard_entries_have_ids_for_profile_links(): void
    {
        $user = User::factory()->create(['name' => 'Новичок Вася']);

        $this->actingAs($user)
            ->get('/leaderboard')
            ->assertInertia(function ($page) use ($user) {
                $entries = collect($page->toArray()['props']['entries']);

                $this->assertCount(12, $entries->pluck('id')->unique());
                $this->assertSame($user->id, $entries->firstWhere('is_me', true)['id']);
                $this->assertSame(1000000, $entries->firstWhere('name', 'Бомбардир_77')['id']);
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
