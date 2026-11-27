<?php

namespace Tests\Unit\Predictions;

use App\Domains\Predictions\HeuristicModel;
use App\Domains\Predictions\LeagueBaseline;
use App\Domains\Predictions\MarketCatalog;
use App\Domains\Predictions\TeamProfile;
use PHPUnit\Framework\TestCase;

class HeuristicModelTest extends TestCase
{
    /** @return array<string, float> ключ "market|line|outcome" */
    private function probs(array $result): array
    {
        $out = [];
        foreach ($result['outcomes'] as $o) {
            $out[$o['market'].'|'.($o['line'] ?? '').'|'.$o['outcome']] = $o['probability'];
        }

        return $out;
    }

    public function test_without_history_uses_league_baseline_and_home_advantage(): void
    {
        $r = (new HeuristicModel)->predict(new TeamProfile, new TeamProfile, new LeagueBaseline);

        $this->assertEqualsWithDelta(1.0, $r['p_home'] + $r['p_draw'] + $r['p_away'], 0.01);
        $this->assertGreaterThan($r['p_away'], $r['p_home']);
        $this->assertEqualsWithDelta(1.45, $r['expected_home'], 0.001);
        $this->assertEqualsWithDelta(1.15, $r['expected_away'], 0.001);
    }

    public function test_strong_attack_vs_weak_defence_makes_home_favourite(): void
    {
        $strong = new TeamProfile(games: 12, goalsFor: 2.6, goalsAgainst: 0.6);
        $weak = new TeamProfile(games: 12, goalsFor: 0.7, goalsAgainst: 2.2);

        $r = (new HeuristicModel)->predict($strong, $weak, new LeagueBaseline);

        $this->assertGreaterThan(0.6, $r['p_home']);
        $this->assertGreaterThan($r['expected_away'], $r['expected_home']);
    }

    public function test_every_market_group_sums_to_one_and_probabilities_are_in_range(): void
    {
        $r = (new HeuristicModel)->predict(
            new TeamProfile(10, 1.5, 1.0, 10, 5.5, 2.1, 0.1),
            new TeamProfile(10, 1.1, 1.4, 10, 4.2, 2.4, 0.2),
            new LeagueBaseline,
        );

        $groups = [];
        foreach ($r['outcomes'] as $o) {
            $this->assertGreaterThanOrEqual(0.01, $o['probability']);
            $this->assertLessThanOrEqual(0.99, $o['probability']);
            $groups[$o['market'].'|'.($o['line'] ?? '')][] = $o['probability'];
        }

        foreach ($groups as $key => $values) {
            $expected = str_starts_with($key, MarketCatalog::DOUBLE_CHANCE) ? 2.0 : 1.0;
            $this->assertEqualsWithDelta($expected, array_sum($values), 0.01, $key);
        }
    }

    public function test_over_probability_falls_as_the_line_grows(): void
    {
        $p = $this->probs((new HeuristicModel)->predict(new TeamProfile, new TeamProfile, new LeagueBaseline));

        $this->assertGreaterThan($p['total_goals|2.5|over'], $p['total_goals|1.5|over']);
        $this->assertGreaterThan($p['total_goals|3.5|over'], $p['total_goals|2.5|over']);
        $this->assertGreaterThan($p['total_corners|10.5|over'], $p['total_corners|8.5|over']);
        $this->assertGreaterThan($p['total_yellows|5.5|over'], $p['total_yellows|3.5|over']);
    }

    public function test_corner_and_card_stats_shift_totals(): void
    {
        $many = new TeamProfile(10, 1.3, 1.3, 10, 7.0, 3.0, 0.1);
        $few = new TeamProfile(10, 1.3, 1.3, 10, 3.0, 1.0, 0.1);

        $high = $this->probs((new HeuristicModel)->predict($many, $many, new LeagueBaseline));
        $low = $this->probs((new HeuristicModel)->predict($few, $few, new LeagueBaseline));

        $this->assertGreaterThan($low['total_corners|9.5|over'], $high['total_corners|9.5|over']);
        $this->assertGreaterThan($low['total_yellows|4.5|over'], $high['total_yellows|4.5|over']);
    }

    public function test_exposes_top_scorelines_sorted(): void
    {
        $r = (new HeuristicModel)->predict(new TeamProfile, new TeamProfile, new LeagueBaseline);

        $this->assertCount(8, $r['scorelines']);
        $this->assertGreaterThanOrEqual($r['scorelines'][1]['probability'], $r['scorelines'][0]['probability']);
    }
}
