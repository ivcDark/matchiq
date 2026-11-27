<?php

namespace App\Repositories;

use App\Enums\PickStatus;
use App\Models\Pick;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Агрегаты рейтинга по действующим picks (void не считается). */
class LeaderboardRepository
{
    /**
     * Очки и счётчики по пользователям; период — по времени старта матча.
     *
     * @return Collection<int, object{user_id: int, points: int, picks: int, correct: int, lost: int, pending: int}>
     */
    public function aggregates(?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        $rows = Pick::query()
            ->join('matches', 'matches.id', '=', 'picks.match_id')
            ->whereNull('picks.superseded_at')
            ->where('picks.status', '!=', PickStatus::Void->value)
            ->when($from, fn ($q) => $q->where('matches.kickoff_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('matches.kickoff_at', '<=', $to))
            ->groupBy('picks.user_id')
            ->selectRaw("picks.user_id, sum(picks.points) as points, count(*) as picks,
                sum(case when picks.status = 'won' then 1 else 0 end) as correct,
                sum(case when picks.status = 'lost' then 1 else 0 end) as lost,
                sum(case when picks.status = 'pending' then 1 else 0 end) as pending")
            ->get();

        return $rows->map(fn ($r) => (object) [
            'user_id' => (int) $r->user_id,
            'points' => (int) $r->points,
            'picks' => (int) $r->picks,
            'correct' => (int) $r->correct,
            'lost' => (int) $r->lost,
            'pending' => (int) $r->pending,
        ]);
    }

    /**
     * Закрытые picks пользователей от свежих к старым — для серии угаданных подряд.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<bool>> user_id → [true, true, false, …] (true = угадал)
     */
    public function settledResults(array $userIds, int $perUser = 30): array
    {
        if ($userIds === []) {
            return [];
        }

        $picks = Pick::query()
            ->join('matches', 'matches.id', '=', 'picks.match_id')
            ->whereIn('picks.user_id', $userIds)
            ->whereNull('picks.superseded_at')
            ->whereIn('picks.status', [PickStatus::Won->value, PickStatus::Lost->value])
            ->orderByDesc('matches.kickoff_at')
            ->get(['picks.user_id', 'picks.status']);

        $byUser = [];
        foreach ($picks as $pick) {
            $list = $byUser[$pick->user_id] ?? [];
            if (count($list) < $perUser) {
                $byUser[$pick->user_id][] = $pick->status === PickStatus::Won;
            }
        }

        return $byUser;
    }
}
