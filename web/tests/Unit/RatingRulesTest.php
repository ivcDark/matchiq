<?php

namespace Tests\Unit;

use App\Support\RatingRules;
use PHPUnit\Framework\TestCase;

class RatingRulesTest extends TestCase
{
    public function test_points_follow_project_formula(): void
    {
        // Примеры из docs/project-context.md и текста «Как работает рейтинг».
        $this->assertSame(20, RatingRules::points(80));
        $this->assertSame(70, RatingRules::points(30));
    }

    public function test_lower_probability_gives_more_points(): void
    {
        $this->assertGreaterThan(RatingRules::points(50), RatingRules::points(10));
        $this->assertSame(0, RatingRules::points(100));
        $this->assertSame(100, RatingRules::points(0));
    }

    public function test_probability_is_clamped(): void
    {
        $this->assertSame(0, RatingRules::points(150));
        $this->assertSame(100, RatingRules::points(-5));
    }
}
