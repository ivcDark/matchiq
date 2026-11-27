<?php

namespace App\Repositories;

use App\Enums\PlayerPosition;
use App\Enums\UpsertOutcome;
use App\Models\Player;
use App\Repositories\Concerns\GeneratesUniqueSlugs;
use App\Repositories\Concerns\UpsertsModels;
use Carbon\CarbonInterface;

class PlayerRepository
{
    use GeneratesUniqueSlugs, UpsertsModels;

    public function find(int $id): ?Player
    {
        return Player::find($id);
    }

    /** @return array{0: Player, 1: UpsertOutcome} */
    public function upsert(
        ?Player $existing,
        int $countryId,
        string $name,
        ?CarbonInterface $birthDate,
        ?PlayerPosition $position,
        ?string $photoUrl,
        array $slugHints = [],
    ): array {
        $onCreate = [];
        if ($existing === null) {
            $onCreate = ['slug' => $this->uniqueSlug(Player::query(), $name, $slugHints)];
        }

        return $this->persist(new Player, $existing, [
            'country_id' => $countryId,
            'name' => $name,
            'birth_date' => $birthDate,
            'position' => $position,
            'photo_url' => $photoUrl,
        ], $onCreate);
    }
}
