<?php

namespace App\Repositories;

use App\Enums\PickStatus;
use App\Models\Pick;

/** Чтение picks для страницы матча (запись — в Services/Picks). */
class PickRepository
{
    public function activeFor(int $userId, int $matchId): ?Pick
    {
        return Pick::query()
            ->with('marketType.outcomes')
            ->active()
            ->where('user_id', $userId)
            ->where('match_id', $matchId)
            ->first();
    }

    /**
     * Сколько действующих picks на каждое событие матча: ключ "marketTypeId|line|outcomeKey".
     *
     * @return array<string, int>
     */
    public function popularity(int $matchId): array
    {
        $rows = Pick::query()
            ->active()
            ->where('match_id', $matchId)
            ->where('status', '!=', PickStatus::Void)
            ->selectRaw('market_type_id, line, outcome_key, count(*) as total')
            ->groupBy('market_type_id', 'line', 'outcome_key')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[self::key($row->market_type_id, $row->line === null ? null : (float) $row->line, $row->outcome_key)] = (int) $row->total;
        }

        return $counts;
    }

    public static function key(int $marketTypeId, ?float $line, string $outcomeKey): string
    {
        return $marketTypeId.'|'.($line === null ? '' : number_format($line, 2, '.', '')).'|'.$outcomeKey;
    }
}
