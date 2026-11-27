<?php

namespace App\Repositories;

use App\Enums\PlayerPosition;
use App\Enums\SquadKind;
use App\Enums\UpsertOutcome;
use App\Models\SquadMembership;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SquadMembershipRepository
{
    /** Действующие заявки команды (left_on IS NULL), ключ — player_id. @return Collection<int, SquadMembership> */
    public function openForTeam(int $teamId): Collection
    {
        return SquadMembership::where('team_id', $teamId)->whereNull('left_on')->get()->keyBy('player_id');
    }

    /**
     * Игрок в составе команды: обновляет позицию открытой заявки либо открывает новую.
     * Дата вступления неизвестна, пока игрок впервые попадает в состав; повторное возвращение — с даты синхронизации.
     */
    public function join(int $teamId, int $playerId, ?PlayerPosition $position, ?SquadMembership $open, CarbonInterface $today): UpsertOutcome
    {
        if ($open !== null) {
            $position ??= $open->position;
            if ($open->position === $position) {
                return UpsertOutcome::Unchanged;
            }
            $open->update(['position' => $position]);

            return UpsertOutcome::Updated;
        }

        $returning = SquadMembership::where('team_id', $teamId)->where('player_id', $playerId)->exists();

        SquadMembership::create([
            'team_id' => $teamId,
            'player_id' => $playerId,
            'position' => $position,
            'joined_on' => $returning ? $today : null,
            'kind' => SquadKind::Permanent,
        ]);

        return UpsertOutcome::Created;
    }

    /** Закрывает заявки: игрок покинул состав. @param  list<int>  $membershipIds */
    public function close(array $membershipIds, CarbonInterface $today): int
    {
        if ($membershipIds === []) {
            return 0;
        }

        return SquadMembership::whereIn('id', $membershipIds)->update(['left_on' => $today->toDateString()]);
    }

    /** Игрок значится только в одной команде: закрывает его действующие заявки в других командах. @param  list<int>  $playerIds */
    public function closeElsewhere(array $playerIds, int $keepTeamId, CarbonInterface $today): int
    {
        if ($playerIds === []) {
            return 0;
        }

        return SquadMembership::whereIn('player_id', $playerIds)
            ->where('team_id', '!=', $keepTeamId)
            ->whereNull('left_on')
            ->update(['left_on' => $today->toDateString()]);
    }
}
