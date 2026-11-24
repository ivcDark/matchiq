<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Моковый профиль игрока. Структура ответа общая для /users/{ник} и будущего /me.
 */
class MockUserProfile
{
    private const CHAMPIONSHIPS = [
        ['id' => 'rpl', 'name' => 'РПЛ', 'weight' => 46],
        ['id' => 'ucl', 'name' => 'Лига чемпионов', 'weight' => 22],
        ['id' => 'fnl', 'name' => 'ФНЛ', 'weight' => 14],
        ['id' => 'cup', 'name' => 'Кубок России', 'weight' => 11],
        ['id' => 'epl', 'name' => 'Премьер-лига Англии', 'weight' => 7],
    ];

    /**
     * Категория → доля прогнозов, события (название, вес, базовый % угадано).
     */
    private const EVENT_CATEGORIES = [
        'results' => [
            'label' => 'Исходы',
            'weight' => 38,
            'events' => [
                ['П1', 34, 52],
                ['Ничья', 12, 31],
                ['П2', 22, 44],
                ['1X', 18, 68],
                ['X2', 14, 61],
            ],
        ],
        'totals' => [
            'label' => 'Тоталы',
            'weight' => 27,
            'events' => [
                ['Тотал больше 2.5', 30, 49],
                ['Тотал меньше 2.5', 26, 51],
                ['Тотал больше 1.5', 22, 72],
                ['Обе забьют — да', 14, 53],
                ['Тотал меньше 3.5', 8, 70],
            ],
        ],
        'handicap' => [
            'label' => 'Фора',
            'weight' => 12,
            'events' => [
                ['Фора 1 (−1.5)', 32, 38],
                ['Фора 2 (+1.5)', 28, 64],
                ['Фора 1 (0)', 22, 47],
                ['Фора 2 (+0.5)', 18, 55],
            ],
        ],
        'corners' => [
            'label' => 'Угловые',
            'weight' => 13,
            'events' => [
                ['Угловые больше 9.5', 38, 50],
                ['Угловые меньше 10.5', 34, 56],
                ['Угловые больше 8.5', 28, 62],
            ],
        ],
        'yellows' => [
            'label' => 'ЖК',
            'weight' => 10,
            'events' => [
                ['ЖК больше 3.5', 40, 54],
                ['ЖК меньше 4.5', 34, 58],
                ['ЖК больше 4.5', 26, 41],
            ],
        ],
    ];

    private const BEST_PICKS = [
        ['Зенит — Спартак', 'РПЛ', 'Ничья', 'Исход матча'],
        ['ЦСКА — Динамо', 'РПЛ', 'Тотал больше 3.5', 'Тотал'],
        ['Краснодар — Локомотив', 'РПЛ', 'Фора 2 (+1.5)', 'Фора'],
        ['Ростов — Ахмат', 'РПЛ', 'П2', 'Исход матча'],
        ['Бавария — Ливерпуль', 'Лига чемпионов', 'Угловые больше 11.5', 'Угловые'],
        ['Сочи — Крылья Советов', 'РПЛ', 'ЖК больше 5.5', 'Жёлтые карточки'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(
        int $id,
        string $nick,
        ?CarbonInterface $registeredAt,
        bool $isMe,
    ): array {
        $season = RatingSeason::forDate();
        $ratings = [];
        $allTime = null;
        foreach (MockLeaderboard::periods($season) as $period) {
            $entry = self::findEntry($period['id'], $id, $nick);
            $ratings[] = [
                'id' => $period['id'],
                'label' => $period['label'],
                'caption' => $period['caption'] ?? null,
                'rank' => $entry['rank'],
                'points' => $entry['points'],
                'picks' => $entry['picks'],
                'correct' => $entry['correct'],
                'accuracy' => $entry['accuracy'],
            ];
            if ($period['id'] === 'all_time') {
                $allTime = $entry;
            }
        }

        $rand = self::rng((string) $id);
        $registeredAt ??= Carbon::create(2025, 8, 1)->addDays($rand(0, 420));

        return [
            'profile' => [
                'id' => $id,
                'nick' => $nick,
                'registered_at' => $registeredAt->toDateString(),
                'registered_label' => $registeredAt->copy()->locale('ru')->translatedFormat('j F Y'),
                'is_me' => $isMe,
                'rank' => $allTime['rank'],
                'points' => $allTime['points'],
                'total_players' => MockLeaderboard::TOTAL_PLAYERS,
            ],
            'season' => $season->toArray(),
            'ratings' => $ratings,
            'championships' => self::championships($allTime['picks'], $rand),
            'bestPick' => self::bestPick($rand),
            'frequentEvents' => self::frequentEvents($allTime['picks'], $rand),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function findEntry(string $period, int $id, string $nick): array
    {
        $me = MockLeaderboard::isMockId($id) ? null : ['id' => $id, 'name' => $nick];

        foreach (MockLeaderboard::entries($period, $me) as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        abort(404);
    }

    /**
     * Детерминированный генератор от id: один игрок — одни и те же цифры.
     */
    private static function rng(string $seed): \Closure
    {
        $state = crc32($seed) ?: 1;

        return function (int $min, int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;

            return $min + ($state >> 8) % ($max - $min + 1);
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function championships(int $totalPicks, \Closure $rand): array
    {
        $weights = [];
        foreach (self::CHAMPIONSHIPS as $i => $league) {
            $weights[$i] = $league['weight'] + $rand(0, 8);
        }

        $counts = self::distribute($totalPicks, $weights);

        $rows = [];
        foreach (self::CHAMPIONSHIPS as $i => $league) {
            $rows[] = [
                'id' => $league['id'],
                'name' => $league['name'],
                'picks' => $counts[$i],
                'share' => $totalPicks > 0 ? (int) round($counts[$i] / $totalPicks * 100) : 0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['picks'] <=> $a['picks']);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function bestPick(\Closure $rand): array
    {
        [$match, $league, $event, $market] = self::BEST_PICKS[$rand(0, count(self::BEST_PICKS) - 1)];

        $probability = $rand(7, 24);
        $date = Carbon::create(2026, 10, 1)->addDays($rand(0, 40));

        return [
            'match' => $match,
            'league' => $league,
            'market' => $market,
            'event' => $event,
            'date_label' => $date->copy()->locale('ru')->translatedFormat('j F Y'),
            'probability' => $probability,
            'odds' => round(100 / $probability, 2),
            'points' => RatingRules::points($probability),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function frequentEvents(int $totalPicks, \Closure $rand): array
    {
        $categoryWeights = [];
        foreach (self::EVENT_CATEGORIES as $category) {
            $categoryWeights[] = $category['weight'] + $rand(0, 6);
        }
        $categoryCounts = self::distribute($totalPicks, $categoryWeights);

        $result = [];
        $index = 0;
        foreach (self::EVENT_CATEGORIES as $id => $category) {
            $eventWeights = array_map(fn (array $e) => $e[1] + $rand(0, 10), $category['events']);
            $eventCounts = self::distribute($categoryCounts[$index], $eventWeights);

            $items = [];
            foreach ($category['events'] as $i => [$name, , $baseHit]) {
                if ($eventCounts[$i] === 0) {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'count' => $eventCounts[$i],
                    'accuracy' => max(20, min(85, $baseHit + $rand(-8, 8))),
                ];
            }
            usort($items, fn (array $a, array $b) => $b['count'] <=> $a['count']);

            $result[] = [
                'id' => $id,
                'label' => $category['label'],
                'total' => $categoryCounts[$index],
                'items' => array_slice($items, 0, 5),
            ];
            $index++;
        }

        return $result;
    }

    /**
     * Делит total по весам так, чтобы сумма долей была ровно total.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private static function distribute(int $total, array $weights): array
    {
        $sum = array_sum($weights) ?: 1;
        $counts = array_map(fn (int $w) => intdiv($total * $w, $sum), $weights);

        $remainder = $total - array_sum($counts);
        arsort($weights);
        foreach (array_keys($weights) as $key) {
            if ($remainder <= 0) {
                break;
            }
            $counts[$key]++;
            $remainder--;
        }

        return $counts;
    }
}
