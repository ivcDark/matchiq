<?php

namespace Tests\Unit\Rating;

use App\Domains\Rating\LeaderboardRanking;
use PHPUnit\Framework\TestCase;

class LeaderboardRankingTest extends TestCase
{
    public function test_sorts_by_points_then_correct_and_shares_rank_on_tie(): void
    {
        $ranked = LeaderboardRanking::rank([
            ['id' => 1, 'points' => 50, 'correct' => 1, 'picks' => 2],
            ['id' => 2, 'points' => 90, 'correct' => 2, 'picks' => 3],
            ['id' => 3, 'points' => 50, 'correct' => 1, 'picks' => 4],
            ['id' => 4, 'points' => 50, 'correct' => 2, 'picks' => 2],
        ]);

        $this->assertSame([2, 4, 1, 3], array_column($ranked, 'id'));
        $this->assertSame([1, 2, 3, 3], array_column($ranked, 'rank'));
    }

    public function test_streak_counts_wins_from_latest(): void
    {
        $this->assertSame(2, LeaderboardRanking::streak([true, true, false, true]));
        $this->assertSame(0, LeaderboardRanking::streak([false, true]));
        $this->assertSame(0, LeaderboardRanking::streak([]));
    }
}
