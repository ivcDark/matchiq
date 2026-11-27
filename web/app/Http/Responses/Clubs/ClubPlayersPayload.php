<?php

namespace App\Http\Responses\Clubs;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use Illuminate\Support\Collection;

final class ClubPlayersPayload
{
    /**
     * Игроки клуба в срезе матчей. Выступления: старт в составе или выход на замену; голы, передачи
     * и карточки — из событий. Минут, ударов, передач, борьбы в воздухе и рейтинга в БД нет — null.
     *
     * @param  array<int, true>  $matchIds  матчи среза (ключи — id)
     * @param  Collection<int, MatchLineup>  $starters
     * @param  Collection<int, MatchEvent>  $events
     * @param  Collection<int, Player>  $players
     * @return list<array<string, mixed>>
     */
    public static function make(array $matchIds, Collection $starters, Collection $events, Collection $players, string $locale): array
    {
        $stats = [];
        $position = [];
        $touch = function (int $playerId) use (&$stats): void {
            $stats[$playerId] ??= ['starts' => 0, 'subs' => 0, 'goals' => 0, 'assists' => 0, 'yellow' => 0, 'red' => 0];
        };

        foreach ($starters as $lineup) {
            if (! isset($matchIds[$lineup->match_id])) {
                continue;
            }
            $touch($lineup->player_id);
            $stats[$lineup->player_id]['starts']++;
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

        $rows = [];
        foreach ($stats as $playerId => $s) {
            $player = $players[$playerId] ?? null;
            if ($player === null) {
                continue;
            }
            $code = $player->position?->value ?? $position[$playerId] ?? null;

            $rows[] = [
                'name' => $player->localized('name', $locale),
                'position' => $code,
                'age' => $player->birth_date?->age,
                'height' => $player->height_cm,
                'weight' => $player->weight_kg,
                'apps' => $s['starts'] + $s['subs'],
                'starts' => $s['starts'],
                'subs' => $s['subs'],
                'minutes' => null,
                'goals' => $s['goals'],
                'assists' => $s['assists'],
                'yellow' => $s['yellow'],
                'red' => $s['red'],
                'shots' => null,
                'passing' => null,
                'aerial' => null,
                'mom' => null,
                'rating' => null,
                '_id' => $playerId,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['apps'], $b['goals'], $b['assists'], $a['_id']] <=> [$a['apps'], $a['goals'], $a['assists'], $b['_id']]);

        return array_map(function (array $row) {
            unset($row['_id']);

            return $row;
        }, $rows);
    }
}
