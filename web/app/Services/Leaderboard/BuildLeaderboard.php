<?php

namespace App\Services\Leaderboard;

use App\Domains\Rating\LeaderboardRanking;
use App\Models\User;
use App\Repositories\LeaderboardRepository;
use App\Support\RatingSeason;
use Carbon\CarbonImmutable;

/** Таблица лидеров из picks: считается на запросе (позже — кэш в `leaderboard_entries`). */
class BuildLeaderboard
{
    public const TOP = 100;

    public function __construct(private readonly LeaderboardRepository $repository) {}

    /**
     * @return array{entries: list<array<string, mixed>>, myEntry: array<string, mixed>|null, totalPlayers: int, currentUserRank: int|null}
     */
    public function handle(string $period, RatingSeason $season, ?User $me = null): array
    {
        [$from, $to] = $this->window($period, $season);
        $aggregates = $this->repository->aggregates($from, $to);

        $users = User::query()->whereIn('id', $aggregates->pluck('user_id'))->pluck('name', 'id');

        $rows = $aggregates->filter(fn ($a) => $users->has($a->user_id))->map(function ($a) use ($users, $me) {
            $settled = $a->correct + $a->lost;

            return [
                'id' => $a->user_id,
                'name' => (string) $users[$a->user_id],
                'picks' => $a->picks,
                'correct' => $a->correct,
                'pending' => $a->pending,
                'accuracy' => $settled > 0 ? (int) round($a->correct / $settled * 100) : null,
                'points' => $a->points,
                'is_me' => $me !== null && $me->id === $a->user_id,
            ];
        })->values()->all();

        $ranked = LeaderboardRanking::rank($rows);
        $top = array_slice($ranked, 0, self::TOP);

        $streaks = $this->repository->settledResults(array_values(array_unique(array_merge(array_column($top, 'id'), $me ? [$me->id] : []))));
        $withStreak = fn (array $row) => $row + ['streak' => LeaderboardRanking::streak($streaks[$row['id']] ?? [])];

        $mine = collect($ranked)->firstWhere('is_me', true);
        $inTop = $mine !== null && in_array($mine['id'], array_column($top, 'id'), true);

        return [
            'entries' => array_map($withStreak, $top),
            'myEntry' => $mine !== null && ! $inTop ? $withStreak($mine) : null,
            'totalPlayers' => count($ranked),
            'currentUserRank' => $mine['rank'] ?? null,
        ];
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private function window(string $period, RatingSeason $season): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'week' => [$now->startOfWeek(), null],
            'month' => [$now->startOfMonth(), null],
            'season' => [$season->start, $season->end],
            default => [null, null],
        };
    }
}
