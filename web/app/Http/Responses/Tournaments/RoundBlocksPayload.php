<?php

namespace App\Http\Responses\Tournaments;

use App\Models\FootballMatch;
use Illuminate\Support\Collection;

final class RoundBlocksPayload
{
    private const ROUND_FALLBACK = 0;

    /**
     * Туры с матчами: внутри тура по времени. Матчи без тура попадают в блок 0.
     *
     * @param  Collection<int, FootballMatch>  $matches
     * @param  callable(FootballMatch): array<string, mixed>  $row
     * @return list<array<string, mixed>>  по возрастанию тура
     */
    public static function make(Collection $matches, callable $row): array
    {
        $byRound = $matches
            ->sortBy(fn (FootballMatch $m) => [$m->kickoff_at->getTimestamp(), $m->id])
            ->groupBy(fn (FootballMatch $m) => $m->round?->number ?? self::ROUND_FALLBACK)
            ->sortKeys();

        $blocks = [];
        foreach ($byRound as $round => $group) {
            $dates = $group->map(fn (FootballMatch $m) => $m->kickoff_at->toDateString());
            $blocks[] = [
                'round' => (int) $round,
                'date_from' => $dates->min(),
                'date_to' => $dates->max(),
                'matches' => $group->map($row)->values()->all(),
            ];
        }

        return $blocks;
    }
}
