<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\MatchOdd;
use App\Repositories\Concerns\UpsertsModels;

class MatchOddRepository
{
    use UpsertsModels;

    /**
     * Коэффициенты матча от провайдера синхронизируются целиком: рынки и исходы, которых больше нет в линии, удаляются.
     *
     * @param  list<array{market_id: int, market_name: ?string, outcome: string, value: float}>  $odds
     * @return array<string, int> outcome → количество
     */
    public function sync(int $matchId, int $providerId, array $odds): array
    {
        $existing = MatchOdd::where('match_id', $matchId)
            ->where('source_provider_id', $providerId)
            ->get()
            ->keyBy(fn (MatchOdd $o) => $o->source_market_id.'|'.$o->outcome);

        $counts = [];
        $seen = [];
        foreach ($odds as $odd) {
            $key = $odd['market_id'].'|'.$odd['outcome'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            [, $outcome] = $this->persist(new MatchOdd, $existing->get($key), [
                'match_id' => $matchId,
                'source_provider_id' => $providerId,
                'source_market_id' => $odd['market_id'],
                'market_name' => $odd['market_name'],
                'outcome' => $odd['outcome'],
                'value' => $odd['value'],
            ]);
            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        $stale = $existing->reject(fn ($o, $key) => isset($seen[$key]))->pluck('id')->all();
        if ($stale !== []) {
            MatchOdd::whereIn('id', $stale)->delete();
            $counts[UpsertOutcome::Updated->value] = ($counts[UpsertOutcome::Updated->value] ?? 0) + count($stale);
        }

        return $counts;
    }
}
