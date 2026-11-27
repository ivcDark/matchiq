<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\PickStatus;
use App\Models\FootballMatch;
use App\Models\MarketType;
use App\Models\MatchPrediction;
use App\Models\MatchTeamStat;
use App\Models\Pick;
use App\Models\PredictionOutcome;
use App\Models\User;
use App\Services\Picks\ResolveMatchPicks;
use App\Services\Predictions\GenerateMatchPredictions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesLeagueData;
use Tests\TestCase;

class PicksTest extends TestCase
{
    use CreatesLeagueData, RefreshDatabase;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 12:00:00');
        $this->createLeague();
        $this->match = $this->match('zenit', 'spartak', '2026-11-27 16:00:00');
        app(GenerateMatchPredictions::class)->handle($this->match);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function outcome(string $code, string $key, ?float $line = null, ?FootballMatch $match = null): PredictionOutcome
    {
        $type = MarketType::where('code', $code)->firstOrFail();

        return PredictionOutcome::query()
            ->where('market_type_id', $type->id)
            ->where('outcome_key', $key)
            ->when($line, fn ($q) => $q->where('line', $line))
            ->whereHas('matchPrediction', fn ($q) => $q->where('match_id', ($match ?? $this->match)->id)->where('is_current', true))
            ->firstOrFail();
    }

    private function place(User $user, PredictionOutcome $outcome, ?FootballMatch $match = null)
    {
        return $this->actingAs($user)->post(route('picks.store', ($match ?? $this->match)->id), ['prediction_outcome_id' => $outcome->id]);
    }

    public function test_generate_creates_current_snapshot_with_all_markets(): void
    {
        $prediction = MatchPrediction::where('match_id', $this->match->id)->sole();

        $this->assertTrue($prediction->is_current);
        $this->assertSame(28, $prediction->outcomes()->count());
        $this->assertGreaterThan(0, $prediction->scorelines()->count());
        $this->assertNotNull($prediction->p_home);

        $this->assertNull(app(GenerateMatchPredictions::class)->handle($this->match));
        $this->assertSame(1, MatchPrediction::count());

        $second = app(GenerateMatchPredictions::class)->handle($this->match, force: true);
        $this->assertTrue($second->is_current);
        $this->assertSame(1, MatchPrediction::where('is_current', true)->count());
    }

    public function test_generate_skips_started_and_finished_matches(): void
    {
        $finished = $this->match('cska', 'dynamo', '2026-11-20 16:00:00', [1, 0]);
        $live = $this->match('spartak', 'cska', '2026-11-27 11:00:00', null, 1, ['status' => MatchStatus::Live]);

        $this->assertNull(app(GenerateMatchPredictions::class)->handle($finished));
        $this->assertNull(app(GenerateMatchPredictions::class)->handle($live));
    }

    public function test_generate_command_fills_upcoming_matches(): void
    {
        $other = $this->match('cska', 'dynamo', '2026-11-28 16:00:00');

        $this->artisan('predictions:generate', ['--days' => 3])->assertSuccessful();

        $this->assertTrue(MatchPrediction::where('match_id', $other->id)->where('is_current', true)->exists());
        $this->assertSame(2, MatchPrediction::count());
    }

    public function test_show_exposes_markets_for_guest_without_pick(): void
    {
        $this->get(route('matches.show', $this->match->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->where('pickOpen', true)
                ->where('userPick', null)
                ->has('markets', 13)
                ->where('markets.0.code', 'result_1x2')
                ->where('markets.0.name', 'Исход матча')
                ->where('markets.0.outcomes.0.label', 'П1')
                ->has('markets.0.outcomes.0.prob')
                ->has('markets.0.outcomes.0.points')
                ->where('markets.0.outcomes.0.popular_pct', null));
    }

    public function test_guest_cannot_place_a_pick(): void
    {
        $outcome = $this->outcome('btts', 'yes');

        $this->post(route('picks.store', $this->match->id), ['prediction_outcome_id' => $outcome->id])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Pick::count());
    }

    public function test_user_places_pick_with_probability_snapshot(): void
    {
        $user = User::factory()->create();
        $outcome = $this->outcome('total_goals', 'over', 2.5);

        $this->place($user, $outcome)->assertRedirect()->assertSessionHasNoErrors();

        $pick = Pick::sole();
        $this->assertSame($user->id, $pick->user_id);
        $this->assertSame($this->match->id, $pick->match_id);
        $this->assertSame($outcome->id, $pick->prediction_outcome_id);
        $this->assertSame('over', $pick->outcome_key);
        $this->assertSame(PickStatus::Pending, $pick->status);
        $this->assertEquals($outcome->probability, $pick->probability_at_pick);

        $pct = (int) round((float) $outcome->probability * 100);
        $this->actingAs($user)->get(route('matches.show', $this->match->id))->assertInertia(fn ($page) => $page
            ->where('userPick.outcome_id', $outcome->id)
            ->where('userPick.label', 'ТБ 2.5')
            ->where('userPick.market', 'Тотал голов 2.5')
            ->where('userPick.status', 'pending')
            ->where('userPick.probability', $pct)
            ->where('userPick.potential_points', 100 - $pct)
            ->where('markets.3.outcomes.0.popular_pct', 100)
            ->where('markets.3.popular_pct', 100));
    }

    public function test_only_one_active_pick_per_match_new_one_replaces_old(): void
    {
        $user = User::factory()->create();

        $this->place($user, $this->outcome('btts', 'yes'));
        $this->place($user, $this->outcome('result_1x2', 'home'));
        $this->place($user, $this->outcome('result_1x2', 'home'));

        $this->assertSame(1, Pick::active()->count());
        $this->assertSame('home', Pick::active()->sole()->outcome_key);
        $this->assertSame(2, Pick::count());
        $this->assertSame(PickStatus::Void, Pick::whereNotNull('superseded_at')->sole()->status);
    }

    public function test_pick_can_be_cancelled_before_kickoff(): void
    {
        $user = User::factory()->create();
        $this->place($user, $this->outcome('btts', 'no'));

        $this->actingAs($user)->delete(route('picks.destroy', $this->match->id))->assertRedirect();

        $this->assertSame(0, Pick::active()->count());
        $this->actingAs($user)->get(route('matches.show', $this->match->id))
            ->assertInertia(fn ($page) => $page->where('userPick', null));
    }

    public function test_pick_is_closed_after_kickoff_and_for_foreign_outcome(): void
    {
        $user = User::factory()->create();
        $outcome = $this->outcome('btts', 'yes');

        $other = $this->match('cska', 'dynamo', '2026-11-28 16:00:00');
        app(GenerateMatchPredictions::class)->handle($other);
        $this->place($user, $outcome, $other)->assertSessionHasErrors('pick');

        Carbon::setTestNow('2026-11-27 16:01:00');
        $this->place($user, $outcome)->assertSessionHasErrors('pick');
        $this->actingAs($user)->delete(route('picks.destroy', $this->match->id))->assertSessionHasErrors('pick');

        $this->assertSame(0, Pick::count());
        $this->actingAs($user)->get(route('matches.show', $this->match->id))
            ->assertInertia(fn ($page) => $page->where('pickOpen', false));
    }

    public function test_resolve_awards_points_for_hit_and_zero_for_miss(): void
    {
        $winner = User::factory()->create();
        $loser = User::factory()->create();
        $over = $this->outcome('total_goals', 'over', 2.5);
        $under = $this->outcome('total_goals', 'under', 2.5);
        $this->place($winner, $over);
        $this->place($loser, $under);

        $this->match->update(['status' => MatchStatus::Finished, 'home_goals' => 2, 'away_goals' => 1]);
        $result = app(ResolveMatchPicks::class)->handle($this->match->fresh());

        $this->assertSame(['resolved' => 2, 'pending' => 0, 'void' => 0], $result);

        $won = Pick::where('user_id', $winner->id)->sole();
        $this->assertSame(PickStatus::Won, $won->status);
        $this->assertSame(100 - (int) round((float) $over->probability * 100), $won->points);
        $this->assertNotNull($won->resolved_at);

        $lost = Pick::where('user_id', $loser->id)->sole();
        $this->assertSame(PickStatus::Lost, $lost->status);
        $this->assertSame(0, $lost->points);

        $this->assertTrue($over->fresh()->is_hit);
        $this->assertFalse($under->fresh()->is_hit);

        $this->assertSame(['resolved' => 0, 'pending' => 0, 'void' => 0], app(ResolveMatchPicks::class)->handle($this->match->fresh()));
    }

    public function test_resolve_waits_for_stats_needed_by_the_market(): void
    {
        $user = User::factory()->create();
        $this->place($user, $this->outcome('total_corners', 'over', 9.5));
        $this->match->update(['status' => MatchStatus::Finished, 'home_goals' => 1, 'away_goals' => 0]);

        $this->assertSame(['resolved' => 0, 'pending' => 1, 'void' => 0], app(ResolveMatchPicks::class)->handle($this->match->fresh()));

        foreach (['zenit' => 6, 'spartak' => 5] as $team => $corners) {
            MatchTeamStat::create([
                'match_id' => $this->match->id, 'team_id' => $this->teams[$team]->id, 'period' => 'full_time',
                'corners' => $corners, 'yellow_cards' => 1, 'red_cards' => 0,
            ]);
        }

        $this->assertSame(1, app(ResolveMatchPicks::class)->handle($this->match->fresh())['resolved']);
        $this->assertSame(PickStatus::Won, Pick::sole()->status);
    }

    public function test_cancelled_match_voids_picks_without_points(): void
    {
        $user = User::factory()->create();
        $this->place($user, $this->outcome('btts', 'yes'));
        $this->match->update(['status' => MatchStatus::Cancelled]);

        $this->artisan('picks:resolve')->assertSuccessful();

        $pick = Pick::sole();
        $this->assertSame(PickStatus::Void, $pick->status);
        $this->assertSame(0, $pick->points);
    }

    public function test_resolve_command_picks_up_finished_matches(): void
    {
        $user = User::factory()->create();
        $this->place($user, $this->outcome('result_1x2', 'home'));
        $this->match->update(['status' => MatchStatus::Finished, 'home_goals' => 3, 'away_goals' => 0]);

        $this->artisan('picks:resolve')->assertSuccessful();

        $this->assertSame(PickStatus::Won, Pick::sole()->status);
        $this->assertGreaterThan(0, Pick::sole()->points);
    }

    public function test_me_lists_own_picks_and_points(): void
    {
        $user = User::factory()->create();
        $this->place($user, $this->outcome('btts', 'yes'));
        $this->match->update(['status' => MatchStatus::Finished, 'home_goals' => 1, 'away_goals' => 1]);
        $this->artisan('picks:resolve');

        $this->actingAs($user)->get('/me')->assertInertia(fn ($page) => $page
            ->where('profile.points', Pick::sole()->points)
            ->has('picks', 1)
            ->where('picks.0.match', 'Зенит — Спартак')
            ->where('picks.0.status', 'won'));
    }
}
