<?php

namespace App\Http\Responses\Matches;

use App\Domains\Matches\DayFilter;
use App\Domains\Matches\LeagueSelection;
use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;

final readonly class MatchesIndexResponse implements Responsable
{
    /**
     * @param  list<array<string, mixed>>  $leagues
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, mixed>  $widgets
     */
    public function __construct(
        private array $leagues,
        private array $matches,
        private LeagueSelection $selection,
        private DayFilter $day,
        private array $widgets,
    ) {}

    public function toResponse($request)
    {
        return Inertia::render('Matches/Index', [
            'leagues' => $this->leagues,
            'matches' => $this->matches,
            'selectedLeagues' => $this->selection->slugs,
            'period' => $this->day->period?->value,
            'date' => $this->day->date->toDateString(),
            'widgets' => $this->widgets,
        ])->toResponse($request);
    }
}
