<?php

namespace Tests\Unit\Tournaments;

use App\Domains\Tournaments\TeamStatsLeaders;
use PHPUnit\Framework\TestCase;

class TeamStatsLeadersTest extends TestCase
{
    private function row(int $team, string $side, array $over = []): array
    {
        return $over + [
            'team_id' => $team, 'side' => $side, 'possession_pct' => 50.0, 'shots' => 10, 'pass_accuracy_pct' => 80.0,
            'yellow_cards' => null, 'red_cards' => null, 'rating' => 6.5,
        ];
    }

    public function test_keeps_top_five_and_skips_metrics_the_source_did_not_send(): void
    {
        $rows = [];
        $names = [];
        foreach (range(1, 7) as $team) {
            $names[$team] = "Команда {$team}";
            $rows[] = $this->row($team, 'home', ['possession_pct' => 40.0 + $team, 'rating' => $team === 7 ? null : 6.0]);
        }

        $out = TeamStatsLeaders::compute($rows, $names);

        $this->assertCount(5, $out['overall']['possession']);
        $this->assertSame([7, 6, 5, 4, 3], array_column($out['overall']['possession'], 'team_id'));
        $this->assertCount(5, $out['overall']['rating']);
        $this->assertNotContains(7, array_column($out['overall']['rating'], 'team_id'));
        $this->assertSame([], $out['away']['possession']);
        $this->assertSame([], $out['overall']['aerial']);
    }

    public function test_missing_cards_count_as_zero_only_when_the_match_has_stats(): void
    {
        $out = TeamStatsLeaders::compute([
            $this->row(1, 'home', ['yellow_cards' => 2]),
            $this->row(1, 'away'),
            $this->row(2, 'home', ['possession_pct' => null, 'yellow_cards' => null, 'red_cards' => null]),
        ], [1 => 'А', 2 => 'Б']);

        $this->assertSame([['rank' => 1, 'team' => 'А', 'team_id' => 1, 'yellow' => 2, 'red' => 0]], $out['overall']['aggression']);
    }

    public function test_aggression_orders_by_yellow_then_red_then_name(): void
    {
        $out = TeamStatsLeaders::compute([
            $this->row(1, 'home', ['yellow_cards' => 2, 'red_cards' => 0]),
            $this->row(2, 'home', ['yellow_cards' => 2, 'red_cards' => 1]),
            $this->row(3, 'home', ['yellow_cards' => 2, 'red_cards' => 1]),
        ], [1 => 'В', 2 => 'Б', 3 => 'А']);

        $this->assertSame([3, 2, 1], array_column($out['overall']['aggression'], 'team_id'));
    }
}
