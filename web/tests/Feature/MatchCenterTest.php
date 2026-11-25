<?php

namespace Tests\Feature;

use App\Support\MockMatchCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MatchCenterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: int, 1: string, 2: string, 3: int, 4: int}> */
    public static function startedMatches(): array
    {
        return [
            'live ЦСКА — Динамо' => [102, 'ЦСКА', 'Динамо', 1, 1],
            'finished Торпедо — Динамо Мх' => [109, 'Торпедо', 'Динамо Мх', 0, 2],
            'finished Пари НН — Факел' => [106, 'Пари НН', 'Факел', 2, 0],
            'finished Оренбург — Химки' => [107, 'Оренбург', 'Химки', 1, 3],
            'finished Inter — Arsenal' => [203, 'Inter', 'Arsenal', 2, 1],
        ];
    }

    public function test_scheduled_match_has_no_match_center(): void
    {
        $this->get('/matches/101')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->where('match.status', 'scheduled')
                ->where('match.minute', null)
                ->where('matchCenter', null)
                ->has('forecast')
                ->has('markets'));
    }

    public function test_unknown_match_stays_scheduled_without_match_center(): void
    {
        $this->get('/matches/1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.status', 'scheduled')
                ->where('matchCenter', null));
    }

    public function test_live_match_exposes_minute_and_match_center(): void
    {
        $this->get('/matches/102')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->where('match.status', 'live')
                ->where('match.minute', MockMatchCenter::LIVE_MINUTE)
                ->where('match.score_home', 1)
                ->where('match.score_away', 1)
                ->where('matchCenter.minute', MockMatchCenter::LIVE_MINUTE)
                ->has('matchCenter.events')
                ->has('matchCenter.stats.match', 8)
                ->has('matchCenter.stats.first_half', 8)
                ->has('matchCenter.stats.second_half', 8)
                ->where('matchCenter.lineups.home.rating', null)
                ->where('matchCenter.lineups.home.players.0.rating', null)
                ->where('matchCenter.lineups.away.players.0.rating', null)
                ->where('matchCenter.best_players', null)
                ->has('matchCenter.lineups.home.players', 11)
                ->has('matchCenter.lineups.away.players', 11)
                ->has('matchCenter.lineups.home.substitutes', 7)
                ->has('matchCenter.lineups.away.substitutes', 7)
                ->where('matchCenter.lineups.home.coach', 'Ф. Челестини')
                ->has('forecast'));
    }

    public function test_finished_match_has_no_minute(): void
    {
        $this->get('/matches/109')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.status', 'finished')
                ->where('match.minute', null)
                ->where('matchCenter.minute', null)
                ->has('matchCenter.events')
                ->has('matchCenter.stats.match', 8)
                ->has('matchCenter.stats.first_half', 8)
                ->has('matchCenter.stats.second_half', 8)
                ->has('matchCenter.best_players.home', 3)
                ->has('matchCenter.best_players.away', 3));
    }

    #[DataProvider('startedMatches')]
    public function test_events_agree_with_score_and_minute(int $id, string $home, string $away, int $scoreHome, int $scoreAway): void
    {
        $center = $this->center($id);
        $events = $center['events'];
        $limit = $center['minute'] ?? 90;

        $goals = array_values(array_filter($events, fn (array $e): bool => $e['type'] === 'goal'));
        $this->assertCount($scoreHome, array_filter($goals, fn (array $e): bool => $e['side'] === 'home'));
        $this->assertCount($scoreAway, array_filter($goals, fn (array $e): bool => $e['side'] === 'away'));

        if ($goals !== []) {
            $last = end($goals);
            $this->assertSame($scoreHome, $last['score_home']);
            $this->assertSame($scoreAway, $last['score_away']);
        }

        $minutes = array_column($events, 'minute');
        $sorted = $minutes;
        sort($sorted);
        $this->assertSame($sorted, $minutes, 'События отсортированы по минуте.');
        foreach ($minutes as $minute) {
            $this->assertLessThanOrEqual($limit, $minute);
        }
    }

    #[DataProvider('startedMatches')]
    public function test_stats_sections_are_consistent(int $id, string $home, string $away, int $scoreHome, int $scoreAway): void
    {
        $center = $this->center($id);
        $rows = [];
        foreach ($center['stats']['match'] as $section) {
            $this->assertContains($section['column'], ['left', 'right']);
            foreach ($section['rows'] as $row) {
                $rows[$row['id']] = $row;
                $this->assertContains($row['fmt'], ['int', 'dec', 'pct', 'signed']);
                $this->assertContains($row['better'], ['high', 'low', null]);
            }
        }

        foreach (['xpts', 'xg', 'shots', 'on-target', 'possession', 'passes', 'tackles', 'fouls', 'yellow', 'red'] as $key) {
            $this->assertArrayHasKey($key, $rows);
        }

        $this->assertSame(100, $rows['possession']['home'] + $rows['possession']['away']);
        $this->assertSame($scoreHome, $rows['goals-open']['home']);
        $this->assertSame($scoreAway, $rows['goals-open']['away']);
        $this->assertSame($rows['on-target']['away'] - $scoreAway, $rows['saves']['home']);
        $this->assertSame($rows['on-target']['home'] - $scoreHome, $rows['saves']['away']);
        $this->assertGreaterThanOrEqual($rows['on-target']['home'], $rows['shots']['home']);
        $this->assertSame('low', $rows['fouls']['better']);
        $this->assertSame('low', $rows['xga']['better']);

        $yellowEvents = array_filter($center['events'], fn (array $e): bool => $e['type'] === 'yellow');
        $this->assertSame(count($yellowEvents), $rows['yellow']['home'] + $rows['yellow']['away']);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function flatRows(array $sections): array
    {
        $rows = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
    }

    #[DataProvider('startedMatches')]
    public function test_stats_periods_are_consistent(int $id, string $home, string $away, int $scoreHome, int $scoreAway): void
    {
        $center = $this->center($id);
        $live = $center['minute'] !== null;
        $stats = $center['stats'];

        $this->assertSame(['match', 'first_half', 'second_half'], array_keys($stats));
        $this->assertNotNull($stats['second_half'], 'К 63-й минуте и в finished 2 тайм уже есть.');

        $match = $this->flatRows($stats['match']);
        $h1 = $this->flatRows($stats['first_half']);
        $h2 = $this->flatRows($stats['second_half']);

        foreach (['goals-open', 'shots', 'on-target', 'saves', 'passes', 'tackles', 'fouls', 'yellow', 'red', 'xg', 'xgot', 'corners'] as $key) {
            foreach (['home', 'away'] as $side) {
                $this->assertEqualsWithDelta(
                    $match[$key][$side],
                    $h1[$key][$side] + $h2[$key][$side],
                    0.011,
                    "Сумма таймов = матч: {$key}/{$side}",
                );
            }
        }

        foreach ([$match, $h1, $h2] as $rows) {
            $this->assertSame(100, $rows['possession']['home'] + $rows['possession']['away']);
            $this->assertGreaterThanOrEqual($rows['on-target']['home'], $rows['shots']['home']);
        }

        $this->assertSame($scoreHome, $h1['goals-open']['home'] + $h2['goals-open']['home']);
        $this->assertSame($scoreAway, $h1['goals-open']['away'] + $h2['goals-open']['away']);
        $this->assertNotSame(
            [$h1['possession']['home'], $h1['passes']['home']],
            [$h2['possession']['home'], $h2['passes']['home']],
            'Периоды считаются отдельно.',
        );

        if ($live) {
            $this->assertLessThan($h1['passes']['home'], $h2['passes']['home'], '2 тайм у live неполный (63′).');
        }
    }

    #[DataProvider('startedMatches')]
    public function test_best_players_and_lineups_are_complete(int $id, string $home, string $away, int $scoreHome, int $scoreAway): void
    {
        $center = $this->center($id);

        foreach (['home', 'away'] as $side) {
            if ($center['minute'] === null) {
                $best = $center['best_players'][$side];
                $this->assertCount(3, $best);
                $ratings = array_column($best, 'rating');
                $sorted = $ratings;
                rsort($sorted);
                $this->assertSame($sorted, $ratings, 'Лучшие игроки отсортированы по рейтингу.');
            } else {
                $this->assertNull($center['best_players']);
            }

            $lineup = $center['lineups'][$side];
            $this->assertCount(11, $lineup['players']);
            $this->assertSame(10, array_sum(array_map('intval', explode('-', $lineup['formation']))));
            $this->assertSame('gk', $lineup['players'][0]['line']);
            if ($center['minute'] === null) {
                $this->assertGreaterThan(4, $lineup['rating']);
                foreach ($lineup['players'] as $player) {
                    $this->assertNotNull($player['rating']);
                }
            } else {
                $this->assertNull($lineup['rating'], 'Оценки команд у live не отдаются.');
                foreach (array_merge($lineup['players'], $lineup['bench']) as $player) {
                    $this->assertNull($player['rating'], 'Оценки игроков у live не отдаются.');
                }
            }

            $this->assertNotSame('', $lineup['coach']);
            $this->assertCount(7, $lineup['substitutes']);
            $this->assertSame(['gk'], array_values(array_unique(array_column(
                array_filter($lineup['substitutes'], fn (array $p): bool => $p['line'] === 'gk'),
                'line',
            ))));

            $starters = array_column($lineup['players'], 'name');
            $bench = array_column($lineup['substitutes'], 'name');
            $this->assertSame([], array_intersect($starters, $bench), 'Запасные не пересекаются со стартовым составом.');
            $this->assertCount(18, array_unique(array_merge($starters, $bench)));

            $numbers = array_merge(array_column($lineup['players'], 'number'), array_column($lineup['substitutes'], 'number'));
            $this->assertCount(count($numbers), array_unique($numbers), 'Номера не повторяются.');

            $cameOn = array_column(array_filter($lineup['substitutes'], fn (array $p): bool => $p['sub_in'] !== null), 'name');
            $this->assertEqualsCanonicalizing(array_column($lineup['bench'], 'name'), $cameOn);
        }
    }

    #[DataProvider('startedMatches')]
    public function test_man_of_the_match_is_single_best_rated_player_of_finished_match(int $id, string $home, string $away, int $scoreHome, int $scoreAway): void
    {
        $center = $this->center($id);
        $live = $center['minute'] !== null;

        $motm = [];
        $maxRating = 0.0;
        foreach (['home', 'away'] as $side) {
            $lineup = $center['lineups'][$side];
            foreach (array_merge($lineup['players'], $lineup['bench']) as $player) {
                $maxRating = max($maxRating, (float) $player['rating']);
                if ($player['motm']) {
                    $motm[] = [$side, $player['number'], $player['rating']];
                }
            }

            $subs = array_values(array_filter($lineup['substitutes'], fn (array $p): bool => $p['motm']));
            $inLineup = array_values(array_filter($lineup['bench'], fn (array $p): bool => $p['motm']));
            $this->assertSame(array_column($inLineup, 'number'), array_column($subs, 'number'));
        }

        if ($live) {
            $this->assertSame([], $motm, 'У live лучшего игрока нет.');
            $this->assertNull($center['best_players']);

            return;
        }

        $this->assertCount(1, $motm, 'Лучший игрок матча один.');
        $this->assertSame($maxRating, (float) $motm[0][2], 'Лучший игрок — с максимальным рейтингом матча.');

        $flagged = [];
        foreach (['home', 'away'] as $side) {
            foreach ($center['best_players'][$side] as $player) {
                if ($player['motm']) {
                    $flagged[] = [$side, $player['number']];
                }
            }
        }
        $this->assertSame([[$motm[0][0], $motm[0][1]]], $flagged, 'Звезда в «Лучших игроках» совпадает с составом.');
        $this->assertTrue($center['best_players'][$motm[0][0]][0]['motm'], 'Лучший игрок команды стоит первым.');
    }

    public function test_known_teams_have_named_coach(): void
    {
        $center = $this->center(102);

        $this->assertSame('Ф. Челестини', $center['lineups']['home']['coach']);
        $this->assertSame('В. Карпин', $center['lineups']['away']['coach']);
    }

    public function test_started_match_keeps_prematch_analytics_props(): void
    {
        $this->get('/matches/106')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('markets')
                ->has('comparison.match')
                ->has('refereeStats.samples')
                ->has('comments')
                ->has('matchCenter.lineups'));
    }

    /**
     * @return array<string, mixed>
     */
    private function center(int $id): array
    {
        $matches = collect([
            102 => ['ЦСКА', 'Динамо', 'live', 1, 1],
            109 => ['Торпедо', 'Динамо Мх', 'finished', 0, 2],
            106 => ['Пари НН', 'Факел', 'finished', 2, 0],
            107 => ['Оренбург', 'Химки', 'finished', 1, 3],
            203 => ['Inter', 'Arsenal', 'finished', 2, 1],
        ]);
        [$home, $away, $status, $sh, $sa] = $matches[$id];

        return MockMatchCenter::build([
            'id' => $id,
            'home' => $home,
            'away' => $away,
            'status' => $status,
            'score_home' => $sh,
            'score_away' => $sa,
        ]);
    }
}
