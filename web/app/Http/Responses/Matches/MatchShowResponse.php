<?php

namespace App\Http\Responses\Matches;

use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;

final readonly class MatchShowResponse implements Responsable
{
    /**
     * @param  array<string, mixed>  $match
     * @param  list<array<string, mixed>>  $standings
     * @param  list<array<string, mixed>>  $formBlocks
     * @param  array<string, mixed>|null  $forecast
     * @param  array<string, mixed>|null  $matchCenter
     * @param  list<string>  $insights
     */
    public function __construct(
        private array $match,
        private ?string $preview,
        private array $insights,
        private ?array $forecast,
        private array $standings,
        private array $formBlocks,
        private ?array $matchCenter,
        private ?string $returnLeague,
    ) {}

    /**
     * Блоки без источника данных (рынки, сравнение, тренды, потери, комментарии…) — явные пустые значения,
     * чтобы Vue показывал empty state, а не демо-цифры.
     */
    public function toResponse($request)
    {
        return Inertia::render('Matches/Show', [
            'match' => $this->match,
            'preview' => $this->preview,
            'insights' => $this->insights,
            'forecast' => $this->forecast,
            'markets' => [],
            'comparison' => ['match' => [], 'first_half' => [], 'second_half' => []],
            'homeAway' => ['tournament' => null, 'last5' => null, 'last10' => null],
            'standings' => $this->standings,
            'scorers' => ['home' => [], 'away' => []],
            'bestPlayers' => ['home' => null, 'away' => null],
            'absences' => ['home' => [], 'away' => []],
            'goalMinutes' => ['scale' => 12, 'scored' => [], 'conceded' => []],
            'formBlocks' => $this->formBlocks,
            'trends' => ['home' => [], 'away' => []],
            'goalProfile' => ['averages' => null, 'ht_ft' => [], 'halves' => null],
            'refereeStats' => null,
            'comments' => [],
            'matchCenter' => $this->matchCenter,
            'userPickEventId' => null,
            'returnLeague' => $this->returnLeague,
        ])->toResponse($request);
    }
}
