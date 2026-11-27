<?php

namespace Tests\Unit\Predictions;

use App\Domains\Predictions\MatchFacts;
use App\Domains\Predictions\OutcomeEvaluator as E;
use PHPUnit\Framework\TestCase;

class OutcomeEvaluatorTest extends TestCase
{
    public function test_result_and_double_chance(): void
    {
        $facts = new MatchFacts(2, 1);

        $this->assertTrue(E::isHit('result_1x2', 'home', null, $facts));
        $this->assertFalse(E::isHit('result_1x2', 'draw', null, $facts));
        $this->assertTrue(E::isHit('double_chance', '1x', null, $facts));
        $this->assertTrue(E::isHit('double_chance', '12', null, $facts));
        $this->assertFalse(E::isHit('double_chance', 'x2', null, $facts));

        $draw = new MatchFacts(1, 1);
        $this->assertTrue(E::isHit('result_1x2', 'draw', null, $draw));
        $this->assertFalse(E::isHit('double_chance', '12', null, $draw));
    }

    public function test_totals_and_btts(): void
    {
        $facts = new MatchFacts(2, 1, 11, 5, 0);

        $this->assertTrue(E::isHit('total_goals', 'over', 2.5, $facts));
        $this->assertFalse(E::isHit('total_goals', 'under', 2.5, $facts));
        $this->assertTrue(E::isHit('total_goals', 'under', 3.5, $facts));
        $this->assertTrue(E::isHit('btts', 'yes', null, $facts));
        $this->assertTrue(E::isHit('total_corners', 'over', 10.5, $facts));
        $this->assertTrue(E::isHit('total_yellows', 'over', 4.5, $facts));
        $this->assertTrue(E::isHit('red_card', 'no', null, $facts));
        $this->assertFalse(E::isHit('btts', 'yes', null, new MatchFacts(2, 0)));
    }

    public function test_missing_data_is_not_resolved(): void
    {
        $facts = new MatchFacts(1, 0);

        $this->assertNull(E::isHit('total_corners', 'over', 9.5, $facts));
        $this->assertNull(E::isHit('total_yellows', 'under', 4.5, $facts));
        $this->assertNull(E::isHit('red_card', 'yes', null, $facts));
        $this->assertNull(E::isHit('unknown_market', 'yes', null, $facts));
    }
}
