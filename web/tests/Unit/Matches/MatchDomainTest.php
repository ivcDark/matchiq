<?php

namespace Tests\Unit\Matches;

use App\Domains\Matches\DayFilter;
use App\Domains\Matches\LeagueSelection;
use App\Domains\Matches\LeagueTable;
use App\Domains\Matches\TeamForm;
use App\Enums\MatchDayPeriod;
use App\Enums\MatchResult;
use App\Models\FootballMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MatchDomainTest extends TestCase
{
    private function match(int $id, int $home, int $away, int $hg, int $ag, string $kickoff): FootballMatch
    {
        $match = new FootballMatch([
            'home_team_id' => $home, 'away_team_id' => $away, 'home_goals' => $hg, 'away_goals' => $ag,
            'kickoff_at' => CarbonImmutable::parse($kickoff),
        ]);
        $match->id = $id;

        return $match;
    }

    public function test_day_filter_names_adjacent_days_and_keeps_custom_dates(): void
    {
        $now = CarbonImmutable::parse('2026-11-27 15:30:00');

        $this->assertSame(MatchDayPeriod::Today, DayFilter::resolve('2026-11-27', null, $now)->period);
        $this->assertSame(MatchDayPeriod::Tomorrow, DayFilter::resolve('2026-11-28', 'yesterday', $now)->period);
        $this->assertNull(DayFilter::resolve('2026-12-31', null, $now)->period);
        $this->assertSame('2026-11-26', DayFilter::resolve(null, 'finished', $now)->date->toDateString());
        $this->assertSame('2026-11-27', DayFilter::resolve('2026-02-31', 'nonsense', $now)->date->toDateString());
    }

    public function test_league_selection_is_exclusive_all_and_drops_unknown(): void
    {
        $active = ['rpl', 'ucl'];

        $this->assertSame(['all'], LeagueSelection::resolve(null, $active)->slugs);
        $this->assertSame(['rpl'], LeagueSelection::resolve('all,rpl,xxx', $active)->slugs);
        $this->assertSame(['rpl', 'ucl'], LeagueSelection::resolve(['rpl', 'ucl', 'rpl'], $active)->slugs);
        $this->assertSame(['all'], LeagueSelection::resolve('xxx', $active)->slugs);
        $this->assertSame($active, LeagueSelection::resolve('all', $active)->effective($active));
        $this->assertNull(LeagueSelection::resolve('all', [])->widgetSlug([]));
    }

    public function test_league_table_ranks_by_points_goal_difference_goals_for(): void
    {
        $table = LeagueTable::compute(new Collection([
            $this->match(1, 1, 2, 2, 0, '2026-08-01'),
            $this->match(2, 3, 1, 1, 1, '2026-08-08'),
            $this->match(3, 2, 3, 3, 0, '2026-08-15'),
        ]));

        $this->assertSame([1, 2, 3], array_column($table, 'team_id'));
        $this->assertSame([4, 3, 1], array_column($table, 'pts'));
        $this->assertSame([1, 2, 3], array_column($table, 'pos'));
        $this->assertSame(['played' => 2, 'wins' => 1, 'draws' => 1, 'losses' => 0, 'gf' => 3, 'ga' => 1, 'gd' => 2],
            array_intersect_key($table[0], array_flip(['played', 'wins', 'draws', 'losses', 'gf', 'ga', 'gd'])));
    }

    public function test_team_form_returns_last_results_before_date_oldest_first(): void
    {
        $form = new TeamForm(new Collection([
            $this->match(1, 1, 2, 1, 0, '2026-08-01'),
            $this->match(2, 2, 1, 2, 2, '2026-08-08'),
            $this->match(3, 1, 3, 0, 1, '2026-08-15'),
            $this->match(4, 1, 2, 5, 0, '2026-09-01'),
        ]));

        $before = CarbonImmutable::parse('2026-08-20');
        $this->assertSame([MatchResult::Win, MatchResult::Draw, MatchResult::Loss], $form->results(1, $before));
        $this->assertSame([MatchResult::Draw, MatchResult::Loss], $form->results(1, $before, 2));
        $this->assertCount(4, $form->results(1));
        $this->assertSame([1, 2, 4], array_map(fn ($m) => $m->id, $form->headToHead(1, 2, CarbonImmutable::parse('2026-12-01'))));
    }
}
