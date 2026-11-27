<?php

namespace App\Repositories;

use App\Enums\RoundType;
use App\Models\Round;
use App\Repositories\Concerns\UpsertsModels;

class RoundRepository
{
    use UpsertsModels;

    public function findByNumber(int $stageId, int $number): ?Round
    {
        return Round::where('stage_id', $stageId)->where('number', $number)->first();
    }

    /** Тур стадии по номеру: создаёт или обновляет только название. */
    public function ensure(int $stageId, int $number, RoundType $type, ?string $name): Round
    {
        [$round] = $this->persist(new Round, $this->findByNumber($stageId, $number), [
            'stage_id' => $stageId,
            'number' => $number,
            'type' => $type,
            'name' => $name,
        ]);

        return $round;
    }
}
