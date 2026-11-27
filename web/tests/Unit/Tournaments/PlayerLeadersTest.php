<?php

namespace Tests\Unit\Tournaments;

use App\Domains\Tournaments\PlayerLeaders;
use PHPUnit\Framework\TestCase;

class PlayerLeadersTest extends TestCase
{
    private function total(int $player, array $over = []): array
    {
        return $over + ['player_id' => $player, 'team_id' => 1, 'minutes' => 0, 'matches' => 0, 'goals' => 0, 'rated' => 0, 'rating_sum' => 0.0];
    }

    private function compute(array $totals, array $keepers = []): array
    {
        $players = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $players[$id] = ['name' => "Игрок{$id}", 'position' => $id === 5 ? 'GK' : 'FW'];
        }

        return PlayerLeaders::compute($totals, $keepers, $players, [1 => 'Зенит', 2 => 'Спартак']);
    }

    public function test_fast_scorers_need_only_a_goal_and_minutes_and_rank_by_goals_per_90(): void
    {
        $out = $this->compute([
            $this->total(1, ['minutes' => 900, 'matches' => 10, 'goals' => 5]),
            $this->total(2, ['minutes' => 600, 'matches' => 7, 'goals' => 4]),
            $this->total(3, ['minutes' => 90, 'matches' => 1, 'goals' => 2]),
            $this->total(4, ['minutes' => 1200, 'matches' => 13, 'goals' => 0]),
        ]);

        $this->assertSame(['Игрок3', 'Игрок2', 'Игрок1'], array_column($out['fastScorers'], 'name'));
        $this->assertSame(
            ['rank' => 2, 'name' => 'Игрок2', 'team' => 'Зенит', 'position' => 'FW', 'minutes' => 600, 'goals' => 4, 'goals_per_90' => 0.6, 'minutes_per_goal' => 150],
            $out['fastScorers'][1],
        );
        $this->assertSame([2.0, 45], [$out['fastScorers'][0]['goals_per_90'], $out['fastScorers'][0]['minutes_per_goal']]);
        $this->assertSame([0.5, 180], [$out['fastScorers'][2]['goals_per_90'], $out['fastScorers'][2]['minutes_per_goal']]);
    }

    public function test_goalkeepers_count_clean_sheets_from_conceded_goals_and_skip_unknown_matches(): void
    {
        $match = fn (int $player, int $minutes, ?int $conceded) => ['player_id' => $player, 'team_id' => 1, 'minutes' => $minutes, 'goals_conceded' => $conceded];
        $keepers = [];
        foreach ([0, 1, 0, 2, 0, 0, 3, 1, 0, 0] as $conceded) {
            $keepers[] = $match(5, 90, $conceded);
        }
        $keepers[] = $match(4, 90, null);
        $keepers[] = $match(4, 30, 0);
        $keepers[] = $match(3, 45, 0);

        $out = $this->compute([], $keepers)['goalkeepers'];

        $this->assertCount(1, $out, 'Игрок 4 без данных об пропущенных; Игрок 3 — короткий матч (меньше 60 минут), сухого нет');
        $this->assertSame(
            ['rank' => 1, 'name' => 'Игрок5', 'team' => 'Зенит', 'position' => 'GK', 'minutes' => 900, 'clean_sheets' => 6, 'clean_sheets_per_90' => 0.6, 'minutes_per_clean_sheet' => 150],
            $out[0],
        );
    }

    public function test_goalkeeper_with_a_short_appearance_does_not_get_a_clean_sheet(): void
    {
        $keepers = array_fill(0, 10, ['player_id' => 5, 'team_id' => 1, 'minutes' => 90, 'goals_conceded' => 1]);
        $keepers[] = ['player_id' => 5, 'team_id' => 1, 'minutes' => 59, 'goals_conceded' => 0];

        $this->assertSame([], $this->compute([], $keepers)['goalkeepers']);
    }

    public function test_minutes_list_orders_by_minutes_then_matches_and_merges_a_transferred_player(): void
    {
        $out = $this->compute([
            $this->total(1, ['minutes' => 500, 'matches' => 6]),
            $this->total(1, ['team_id' => 2, 'minutes' => 700, 'matches' => 8]),
            $this->total(2, ['minutes' => 1200, 'matches' => 14]),
            $this->total(3, ['minutes' => 1200, 'matches' => 13]),
            $this->total(4, ['minutes' => 0, 'matches' => 0]),
        ])['minutes'];

        $this->assertSame(['Игрок1', 'Игрок2', 'Игрок3'], array_column($out, 'name'));
        $this->assertSame([1200, 1200, 1200], array_column($out, 'minutes'));
        $this->assertSame([14, 14, 13], array_column($out, 'matches'));
        $this->assertSame('Спартак', $out[0]['team']);
    }

    public function test_ratings_have_no_matches_minimum_and_average_the_rating(): void
    {
        $out = $this->compute([
            $this->total(1, ['rated' => 8, 'rating_sum' => 60.0]),
            $this->total(2, ['rated' => 10, 'rating_sum' => 75.0]),
            $this->total(3, ['rated' => 1, 'rating_sum' => 8.1]),
            $this->total(4, ['rated' => 9, 'rating_sum' => 67.5]),
            $this->total(5, ['rated' => 0, 'rating_sum' => 0.0]),
        ])['ratings'];

        $this->assertSame(['Игрок3', 'Игрок2', 'Игрок4', 'Игрок1'], array_column($out, 'name'));
        $this->assertSame([8.1, 7.5, 7.5, 7.5], array_column($out, 'rating'));
        $this->assertSame([1, 10, 9, 8], array_column($out, 'matches'));
    }

    public function test_every_list_is_empty_without_data_and_capped(): void
    {
        $this->assertSame(['fastScorers' => [], 'goalkeepers' => [], 'minutes' => [], 'ratings' => []], $this->compute([]));

        $players = [];
        $totals = [];
        foreach (range(1, 80) as $id) {
            $players[$id] = ['name' => "И{$id}", 'position' => null];
            $totals[] = $this->total($id, ['minutes' => 100 + $id, 'matches' => 5]);
        }
        $out = PlayerLeaders::compute($totals, [], $players, [1 => 'Зенит']);

        $this->assertCount(PlayerLeaders::LIMIT, $out['minutes']);
        $this->assertSame(180, $out['minutes'][0]['minutes']);
    }

    public function test_goalkeeper_with_one_clean_sheet_and_few_minutes_is_listed(): void
    {
        $out = $this->compute([], [['player_id' => 5, 'team_id' => 1, 'minutes' => 90, 'goals_conceded' => 0]])['goalkeepers'];

        $this->assertSame(
            ['rank' => 1, 'name' => 'Игрок5', 'team' => 'Зенит', 'position' => 'GK', 'minutes' => 90, 'clean_sheets' => 1, 'clean_sheets_per_90' => 1.0, 'minutes_per_clean_sheet' => 90],
            $out[0],
        );
    }

    public function test_names_are_compacted_to_initial_and_surname(): void
    {
        $players = [
            1 => ['name' => 'Aleksandr Sobolev', 'position' => 'FW'],
            2 => ['name' => 'Мартин Глушенков', 'position' => 'FW'],
            3 => ['name' => 'Pelé', 'position' => null],
            4 => ['name' => '', 'position' => null],
        ];
        $totals = [];
        foreach ([1, 2, 3, 4] as $id) {
            $totals[] = $this->total($id, ['minutes' => 500 - $id, 'matches' => 5]);
        }

        $out = PlayerLeaders::compute($totals, [], $players, [1 => 'Зенит']);

        $this->assertSame(['A. Sobolev', 'М. Глушенков', 'Pelé', ''], array_column($out['minutes'], 'name'));
    }
}
