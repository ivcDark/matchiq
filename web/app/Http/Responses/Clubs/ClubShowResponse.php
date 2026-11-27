<?php

namespace App\Http\Responses\Clubs;

use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;

final readonly class ClubShowResponse implements Responsable
{
    /** @param  array<string, mixed>  $props */
    public function __construct(private array $props) {}

    public function toResponse($request)
    {
        return Inertia::render('Clubs/Show', $this->props)->toResponse($request);
    }
}
