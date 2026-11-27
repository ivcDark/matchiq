<?php

namespace App\Repositories;

use App\Enums\AbsenceType;
use App\Models\PlayerAbsence;
use App\Repositories\Concerns\UpsertsModels;

class PlayerAbsenceRepository
{
    use UpsertsModels;

    /**
     * Текущие отсутствия игроков команды из источника. Отсутствие — (игрок, тип, дата начала); прежние активные, которых
     * в списке больше нет, закрываются (`is_active = false`), а не удаляются.
     *
     * @param  list<array{player_id: int, type: AbsenceType, reason: ?string, since: ?string, expected_return: ?string}>  $absences
     * @return array<string, int> outcome → количество
     */
    public function syncForTeam(int $teamId, array $absences): array
    {
        $key = fn (int $playerId, AbsenceType $type, ?string $since): string => $playerId.'|'.$type->value.'|'.($since ?? '');

        $active = PlayerAbsence::where('team_id', $teamId)->active()->get()
            ->keyBy(fn (PlayerAbsence $a) => $key($a->player_id, $a->type, $a->since?->format('Y-m-d')));

        $counts = [];
        $seen = [];
        foreach ($absences as $row) {
            $k = $key($row['player_id'], $row['type'], $row['since']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            [, $outcome] = $this->persist(new PlayerAbsence, $active->get($k), [
                'player_id' => $row['player_id'],
                'team_id' => $teamId,
                'type' => $row['type'],
                'reason' => $row['reason'],
                'since' => $row['since'],
                'expected_return' => $row['expected_return'],
                'is_active' => true,
            ]);
            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
        }

        $ended = $active->reject(fn ($a, $k) => isset($seen[$k]))->pluck('id')->all();
        if ($ended !== []) {
            PlayerAbsence::whereIn('id', $ended)->update(['is_active' => false, 'updated_at' => now()]);
            $counts['updated'] = ($counts['updated'] ?? 0) + count($ended);
        }

        return $counts;
    }
}
