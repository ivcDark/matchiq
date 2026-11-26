<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\Season;
use App\Repositories\Concerns\UpsertsModels;
use Illuminate\Support\Str;

class SeasonRepository
{
    use UpsertsModels;

    public function find(int $id): ?Season
    {
        return Season::find($id);
    }

    public function findBySlug(int $competitionId, string $slug): ?Season
    {
        return Season::where('competition_id', $competitionId)->where('slug', $slug)->first();
    }

    public function findCurrent(int $competitionId): ?Season
    {
        return Season::where('competition_id', $competitionId)->where('is_current', true)->first();
    }

    public function slugFor(string $label): string
    {
        return Str::slug($label) ?: $label;
    }

    /** @return array{0: Season, 1: UpsertOutcome} */
    public function upsert(?Season $existing, int $competitionId, string $label, bool $isCurrent): array
    {
        if ($isCurrent) {
            // Частичный unique-индекс: у соревнования один текущий сезон.
            Season::where('competition_id', $competitionId)
                ->where('is_current', true)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->update(['is_current' => false]);
        }

        return $this->persist(new Season, $existing, [
            'competition_id' => $competitionId,
            'label' => $label,
            'is_current' => $isCurrent,
        ], ['slug' => $this->slugFor($label)]);
    }
}
