<?php

namespace Tests\Feature;

use App\Enums\PickStatus;
use App\Models\FootballMatch;
use App\Models\MarketType;
use App\Models\ModelVersion;
use App\Models\Pick;
use App\Models\User;
use App\Repositories\MarketTypeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesLeagueData;
use Tests\TestCase;

class LeaderboardPageTest extends TestCase
{
    use CreatesLeagueData, RefreshDatabase;

    private MarketType $market;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 12:00:00');
        $this->createLeague();
        $this->market = app(MarketTypeRepository::class)->syncCatalog()['btts'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pick(User $user, FootballMatch $match, PickStatus $status, int $points, array $attrs = []): Pick
    {
        $prediction = $match->predictions()->firstOrCreate(
            ['is_current' => true],
            ['model_version_id' => ModelVersion::firstOrCreate(['code' => 't'], ['name' => 't', 'version' => '1'])->id, 'generated_at' => now()],
        );
        $outcome = $prediction->outcomes()->firstOrCreate(
            ['market_type_id' => $this->market->id, 'outcome_key' => 'yes'],
            ['probability' => 0.4],
        );

        return Pick::create($attrs + [
            'user_id' => $user->id, 'match_id' => $match->id, 'prediction_outcome_id' => $outcome->id,
            'market_type_id' => $this->market->id, 'outcome_key' => 'yes', 'probability_at_pick' => 0.4,
            'status' => $status, 'points' => $points, 'picked_at' => now(),
        ]);
    }

    public function test_empty_leaderboard_for_guest(): void
    {
        $this->get('/leaderboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Leaderboard/Index')
            ->where('period', 'season')
            ->has('periods', 4)
            ->where('entries', [])
            ->where('myEntry', null)
            ->where('totalPlayers', 0)
            ->where('currentUserRank', null));
    }

    public function test_entries_are_ranked_by_points_with_accuracy_and_streak(): void
    {
        $ann = User::factory()->create(['name' => 'Аня']);
        $boris = User::factory()->create(['name' => 'Борис']);
        $newbie = User::factory()->create(['name' => 'Новичок']);

        $m1 = $this->match('zenit', 'spartak', '2026-11-20 16:00:00', [1, 1]);
        $m2 = $this->match('cska', 'dynamo', '2026-11-21 16:00:00', [0, 0]);
        $m3 = $this->match('zenit', 'cska', '2026-11-22 16:00:00', [2, 1]);
        $m4 = $this->match('spartak', 'dynamo', '2026-11-28 16:00:00');

        $this->pick($ann, $m1, PickStatus::Won, 60);
        $this->pick($ann, $m2, PickStatus::Lost, 0);
        $this->pick($ann, $m3, PickStatus::Won, 40);
        $this->pick($boris, $m1, PickStatus::Won, 80);
        $this->pick($newbie, $m4, PickStatus::Pending, 0);
        $this->pick($boris, $m4, PickStatus::Void, 0, ['superseded_at' => now()]);

        $this->get('/leaderboard')->assertInertia(fn ($page) => $page
            ->where('totalPlayers', 3)
            ->where('entries.0.name', 'Аня')
            ->where('entries.0.rank', 1)
            ->where('entries.0.points', 100)
            ->where('entries.0.picks', 3)
            ->where('entries.0.correct', 2)
            ->where('entries.0.accuracy', 67)
            ->where('entries.0.streak', 1)
            ->where('entries.1.name', 'Борис')
            ->where('entries.1.points', 80)
            ->where('entries.1.picks', 1)
            ->where('entries.1.streak', 1)
            ->where('entries.2.name', 'Новичок')
            ->where('entries.2.points', 0)
            ->where('entries.2.pending', 1)
            ->where('entries.2.accuracy', null));
    }

    public function test_periods_filter_by_match_kickoff(): void
    {
        $user = User::factory()->create();
        $old = $this->match('zenit', 'spartak', '2025-05-01 16:00:00', [1, 1]);
        $recent = $this->match('cska', 'dynamo', '2026-11-26 16:00:00', [1, 1]);
        $this->pick($user, $old, PickStatus::Won, 70);
        $this->pick($user, $recent, PickStatus::Won, 30);

        $this->get('/leaderboard?period=all_time')->assertInertia(fn ($p) => $p->where('entries.0.points', 100));
        $this->get('/leaderboard?period=season')->assertInertia(fn ($p) => $p->where('entries.0.points', 30));
        $this->get('/leaderboard?period=week')->assertInertia(fn ($p) => $p->where('entries.0.points', 30));
        $this->get('/leaderboard?period=month')->assertInertia(fn ($p) => $p->where('entries.0.points', 30));
    }

    public function test_current_user_is_highlighted_and_pinned_when_outside_top(): void
    {
        $match = $this->match('zenit', 'spartak', '2026-11-20 16:00:00', [1, 1]);
        foreach (range(1, 101) as $i) {
            $this->pick(User::factory()->create(), $match, PickStatus::Won, 200 - $i);
        }
        $me = User::factory()->create(['name' => 'Я']);
        $this->pick($me, $match, PickStatus::Lost, 0);

        $this->actingAs($me)->get('/leaderboard')->assertInertia(fn ($page) => $page
            ->has('entries', 100)
            ->where('entries.0.is_me', false)
            ->where('myEntry.name', 'Я')
            ->where('myEntry.is_me', true)
            ->where('myEntry.rank', 102)
            ->where('currentUserRank', 102)
            ->where('totalPlayers', 102));
    }

    public function test_current_user_inside_top_is_marked_without_pinned_row(): void
    {
        $me = User::factory()->create(['name' => 'Я']);
        $match = $this->match('zenit', 'spartak', '2026-11-20 16:00:00', [1, 1]);
        $this->pick($me, $match, PickStatus::Won, 60);

        $this->actingAs($me)->get('/leaderboard')->assertInertia(fn ($page) => $page
            ->where('entries.0.is_me', true)
            ->where('myEntry', null)
            ->where('currentUserRank', 1));
    }
}
