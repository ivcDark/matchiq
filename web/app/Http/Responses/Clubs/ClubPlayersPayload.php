<?php

namespace App\Http\Responses\Clubs;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\SquadMembership;
use Illuminate\Support\Collection;

final class ClubPlayersPayload
{
    /**
     * Игроки клуба в срезе матчей. Выступления: старт в составе, выход на замену или минуты в `match_player_stats`;
     * голы (в том числе с пенальти), передачи и карточки — из событий. Минуты, удары за матч, точность передач
     * (`extra.passes_accurate / passes_total`), выигранные верховые за матч (`extra.duels_aerial_won`), лучший игрок
     * матча и средняя оценка — из `match_player_stats`; без данных — null.
     *
     * @param  array<int, true>  $matchIds  матчи среза (ключи — id)
     * @param  Collection<int, MatchLineup>  $starters
     * @param  Collection<int, MatchEvent>  $events
     * @param  Collection<int, Player>  $players
     * @param  Collection<int, MatchPlayerStat>  $playerStats
     * @param  Collection<int, SquadMembership>|null  $roster  действующий состав (по id игрока): в таблицу попадают
     *                                                         и игроки без выступлений, `in_squad` отличает их от ушедших
     * @param  array<int, int>  $shirtNumbers  последний игровой номер игрока в клубе по заявкам на матчи (резерв, когда
     *                                         в `squad_memberships.shirt_number` пусто); без данных `number` = null
     * @return list<array<string, mixed>>
     */
    public static function make(array $matchIds, Collection $starters, Collection $events, Collection $players, string $locale, ?Collection $playerStats = null, ?Collection $roster = null, array $shirtNumbers = []): array
    {
        $stats = [];
        $position = [];
        $appeared = [];
        $touch = function (int $playerId) use (&$stats): void {
            $stats[$playerId] ??= ['starts' => 0, 'subs' => 0, 'goals' => 0, 'pens' => 0, 'assists' => 0, 'yellow' => 0, 'red' => 0];
        };

        foreach ($starters as $lineup) {
            if (! isset($matchIds[$lineup->match_id])) {
                continue;
            }
            $touch($lineup->player_id);
            $stats[$lineup->player_id]['starts']++;
            $appeared[$lineup->match_id][$lineup->player_id] = true;
            $position[$lineup->player_id] ??= $lineup->position;
        }

        foreach ($events as $event) {
            if (! isset($matchIds[$event->match_id])) {
                continue;
            }

            switch ($event->type) {
                case MatchEventType::Goal:
                case MatchEventType::PenaltyGoal:
                    if ($event->player_id !== null) {
                        $touch($event->player_id);
                        $stats[$event->player_id]['goals']++;
                        if ($event->type === MatchEventType::PenaltyGoal) {
                            $stats[$event->player_id]['pens']++;
                        }
                    }
                    if ($event->related_player_id !== null) {
                        $touch($event->related_player_id);
                        $stats[$event->related_player_id]['assists']++;
                    }
                    break;
                case MatchEventType::Substitution:
                    if ($event->related_player_id !== null) {
                        $touch($event->related_player_id);
                        $stats[$event->related_player_id]['subs']++;
                        $appeared[$event->match_id][$event->related_player_id] = true;
                    }
                    break;
                case MatchEventType::Yellow:
                case MatchEventType::SecondYellow:
                    if ($event->player_id !== null) {
                        $touch($event->player_id);
                        $stats[$event->player_id]['yellow']++;
                    }
                    if ($event->type === MatchEventType::SecondYellow && $event->player_id !== null) {
                        $stats[$event->player_id]['red']++;
                    }
                    break;
                case MatchEventType::Red:
                    if ($event->player_id !== null) {
                        $touch($event->player_id);
                        $stats[$event->player_id]['red']++;
                    }
                    break;
                default:
                    break;
            }
        }

        $played = [];
        foreach ($playerStats ?? [] as $stat) {
            if (! isset($matchIds[$stat->match_id]) || ($stat->minutes !== null && $stat->minutes <= 0)) {
                continue;
            }
            $touch($stat->player_id);
            $played[$stat->player_id][] = $stat;
            if ($stat->minutes !== null && ! isset($appeared[$stat->match_id][$stat->player_id])) {
                $stats[$stat->player_id]['subs']++;
            }
        }

        foreach ($roster ?? [] as $membership) {
            $touch($membership->player_id);
        }

        $rows = [];
        foreach ($stats as $playerId => $s) {
            $player = $players[$playerId] ?? null;
            if ($player === null) {
                continue;
            }
            $metrics = self::metrics($played[$playerId] ?? []);
            $code = $player->position?->value ?? $roster?->get($playerId)?->position?->value ?? $position[$playerId] ?? null;

            $rows[] = [
                'name' => $player->localized('name', $locale),
                'position' => $code,
                'number' => $roster?->get($playerId)?->shirt_number ?? $shirtNumbers[$playerId] ?? null,
                'age' => $player->birth_date?->age,
                'apps' => $s['starts'] + $s['subs'],
                'starts' => $s['starts'],
                'subs' => $s['subs'],
                'minutes' => $metrics['minutes'],
                'goals' => $s['goals'],
                'pens' => $s['pens'],
                'assists' => $s['assists'],
                'yellow' => $s['yellow'],
                'red' => $s['red'],
                'shots' => $metrics['shots'],
                'passing' => $metrics['passing'],
                'aerial' => $metrics['aerial'],
                'mom' => $metrics['mom'],
                'rating' => $metrics['rating'],
                'in_squad' => $roster === null || $roster->isEmpty() ? null : $roster->has($playerId),
                '_id' => $playerId,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['apps'], $b['goals'], $b['assists'], $a['_id']] <=> [$a['apps'], $a['goals'], $a['assists'], $b['_id']]);

        return array_map(function (array $row) {
            unset($row['_id']);

            return $row;
        }, $rows);
    }

    /**
     * @param  list<MatchPlayerStat>  $stats  строки игрока в матчах среза
     * @return array{minutes: ?int, shots: ?float, passing: ?float, aerial: ?float, mom: ?int, rating: ?float}
     */
    private static function metrics(array $stats): array
    {
        $empty = ['minutes' => null, 'shots' => null, 'passing' => null, 'aerial' => null, 'mom' => null, 'rating' => null];
        if ($stats === []) {
            return $empty;
        }

        $rows = collect($stats);
        $avg = fn (Collection $values, int $digits): ?float => $values->isEmpty() ? null : round($values->avg(), $digits);
        $minutes = $rows->pluck('minutes')->filter(fn ($v) => $v !== null);
        $accurate = $rows->sum(fn ($r) => (int) ($r->extra['passes_accurate'] ?? 0));
        $total = $rows->sum(fn ($r) => (int) ($r->extra['passes_total'] ?? 0));

        return [
            'minutes' => $minutes->isEmpty() ? null : (int) $minutes->sum(),
            'shots' => $avg($rows->pluck('shots')->filter(fn ($v) => $v !== null), 1),
            'passing' => $total > 0 ? round($accurate / $total * 100, 1) : null,
            'aerial' => $avg(
                $rows->map(fn ($r) => $r->extra['duels_aerial_won'] ?? null)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v),
                1,
            ),
            'mom' => (int) $rows->where('is_motm', true)->count(),
            'rating' => $avg($rows->pluck('rating')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v), 2),
        ];
    }
}
