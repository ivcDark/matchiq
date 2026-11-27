<?php

namespace App\Http\Responses\Tournaments;

use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;

final readonly class TournamentShowResponse implements Responsable
{
    /** @param  array<string, mixed>  $props */
    public function __construct(private array $props) {}

    public function toResponse($request)
    {
        return Inertia::render('Tournaments/Show', $this->props)->toResponse($request);
    }
}
