<?php

namespace App\Support;

/**
 * Моковая статистика матча для live / finished: хроника, секции статистики, лучшие игроки, составы.
 * Всё детерминировано от id матча и счёта, поэтому события и цифры не расходятся между собой
 * (голы в хронике = счёт, сейвы вратаря = удары в створ соперника минус пропущенные и т.д.).
 * Для live все «объёмные» показатели масштабируются минутой, хроника обрезается по ней.
 */
class MockMatchCenter
{
    public const LIVE_MINUTE = 63;

    private const FORMATIONS = ['4-2-3-1', '4-3-3', '3-5-2', '4-4-2', '4-1-4-1'];

    private const SHIRTS = [1, 23, 4, 5, 3, 6, 8, 10, 7, 11, 9, 12, 14, 20];

    /**
     * Первые 11 — стартовый состав по линиям (вратарь, защита … атака), далее 3 запасных полевых.
     *
     * @var array<string, array{formation: string, players: list<string>}>
     */
    private const SQUADS = [
        'ЦСКА' => ['formation' => '4-2-3-1', 'players' => ['И. Акинфеев', 'К. Маричал', 'М. Диарра', 'Мойзес', 'Ф. Чаллов', 'М. Гонсалес', 'Й. Алвес', 'И. Обляков', 'К. Баттаглия', 'Н. Мело', 'Т. Жоао', 'Р. Леонидас', 'Д. Васин', 'А. Мартьянов']],
        'Динамо' => ['formation' => '4-3-3', 'players' => ['Т. Лещук', 'Н. Васильев', 'Н. Касерес', 'И. Захарян', 'К. Тюкавин', 'Д. Фомин', 'Р. Фернандес', 'Д. Скопинцев', 'М. Гутьеррес', 'Н. Бегич', 'В. Моисеев', 'А. Миранчук', 'К. Брагинец', 'Д. Тюрин']],
        'Торпедо' => ['formation' => '4-4-2', 'players' => ['А. Лусин', 'В. Брызгалов', 'Д. Кузнецов', 'А. Бобров', 'С. Лепёхин', 'И. Пинюк', 'М. Бокоев', 'Д. Фёдоров', 'К. Мусаев', 'А. Лобан', 'Я. Рыбчинский', 'Т. Лазарев', 'Н. Рыльский', 'А. Исаев']],
        'Динамо Мх' => ['formation' => '4-2-3-1', 'players' => ['С. Хайбулаев', 'Г. Шарипов', 'Д. Абдулаев', 'М. Исмаилов', 'А. Гаджиев', 'Р. Магомедов', 'К. Алиев', 'А. Рамазанов', 'М. Саидов', 'З. Расулов', 'Г. Абакаров', 'И. Курбанов', 'А. Омаров', 'Т. Исаев']],
        'Пари НН' => ['formation' => '3-5-2', 'players' => ['Н. Мучи', 'В. Тарасов', 'А. Рябков', 'Д. Мелёхин', 'З. Кехрия', 'К. Штраус', 'Л. Бенуа', 'Т. Хурцилава', 'Д. Куницын', 'Е. Оганесян', 'А. Лобанов', 'С. Мирзов', 'Р. Рахимов', 'И. Щетинин']],
        'Факел' => ['formation' => '4-4-2', 'players' => ['А. Шишкин', 'Д. Клейменов', 'К. Яхшибоев', 'В. Колесов', 'И. Тарасов', 'Н. Бурмистров', 'М. Павлов', 'С. Гусев', 'А. Ковалёв', 'Р. Исаев', 'Д. Овчинников', 'Л. Зимин', 'М. Фомичёв', 'Т. Белов']],
        'Оренбург' => ['formation' => '4-1-4-1', 'players' => ['Д. Тюкавин', 'В. Фёдоров', 'Н. Шамшурин', 'А. Рябов', 'Д. Хвостунов', 'М. Волков', 'Е. Мельников', 'Д. Воробьёв', 'Р. Голенков', 'Д. Сорокин', 'К. Лобов', 'Н. Захаров', 'И. Исаев', 'С. Миронов']],
        'Химки' => ['formation' => '4-3-3', 'players' => ['Е. Рапопорт', 'С. Бородин', 'В. Кузьмин', 'А. Ионов', 'Д. Дорофеев', 'А. Хазиев', 'К. Мельник', 'И. Трофимов', 'Ж. Ахметов', 'Д. Белов', 'В. Смирнов', 'Н. Прохоров', 'Т. Андреев', 'М. Орлов']],
        'Inter' => ['formation' => '3-5-2', 'players' => ['Я. Зоммер', 'Ф. Ачерби', 'А. Бастони', 'Ф. Кармона', 'Д. Дюмфрис', 'Н. Барелла', 'Х. Чалханоглу', 'Ф. Диманш', 'Ф. Дармиан', 'Л. Мартинес', 'М. Тюрам', 'К. Асллани', 'М. Дарвин', 'Д. Фрэттези']],
        'Arsenal' => ['formation' => '4-3-3', 'players' => ['Д. Райя', 'Б. Уайт', 'В. Салиба', 'Г. Мартинелли', 'Ж. Тимбер', 'М. Эдегор', 'Д. Райс', 'М. Лоренцо', 'Б. Сака', 'К. Хаверц', 'Г. Жезус', 'Л. Тросcар', 'Э. Нуанери', 'Т. Партей']],
    ];

    /**
     * Дальние запасные (вратарь, защитник, полузащитник, нападающий) и главный тренер.
     *
     * @var array<string, array{coach: string, reserves: list<string>}>
     */
    private const STAFF = [
        'ЦСКА' => ['coach' => 'Ф. Челестини', 'reserves' => ['В. Червяков', 'Д. Глушенков', 'К. Кисляков', 'Я. Хаккарайнен']],
        'Динамо' => ['coach' => 'В. Карпин', 'reserves' => ['А. Шунин', 'В. Тюкавин', 'Д. Гарсия', 'Э. Кахриманович']],
        'Торпедо' => ['coach' => 'И. Черевченко', 'reserves' => ['Д. Мирошниченко', 'Р. Рыков', 'А. Сергеев', 'Н. Дорохов']],
        'Динамо Мх' => ['coach' => 'Х. Гаджиев', 'reserves' => ['Р. Гусейнов', 'Т. Муртазалиев', 'А. Алиев', 'Ш. Ахмедов']],
        'Пари НН' => ['coach' => 'С. Нерестюк', 'reserves' => ['Е. Вялов', 'М. Коротков', 'Д. Власов', 'К. Дроздов']],
        'Факел' => ['coach' => 'Р. Шарипов', 'reserves' => ['М. Волков', 'А. Козлов', 'Н. Романов', 'Д. Соколов']],
        'Оренбург' => ['coach' => 'В. Слышков', 'reserves' => ['А. Бобров', 'И. Власов', 'Е. Сидоров', 'М. Рыбаков']],
        'Химки' => ['coach' => 'С. Потапов', 'reserves' => ['Н. Васильев', 'К. Орлов', 'А. Ермаков', 'Д. Назаров']],
        'Inter' => ['coach' => 'К. Киву', 'reserves' => ['Ю. Мартинес', 'А. Ауровер', 'Р. Фрозиннони', 'А. Коррея']],
        'Arsenal' => ['coach' => 'М. Артета', 'reserves' => ['К. Хэнкок', 'Б. Уайт мл.', 'Э. Смит-Роу', 'Л. Мартинелли']],
    ];

    private const RESERVE_SHIRTS = [13, 15, 17, 22];

    /**
     * @param  array<string, mixed>  $match  демо-матч (id, home, away, status, score_home, score_away)
     * @return array<string, mixed>|null
     */
    public static function build(array $match): ?array
    {
        $status = $match['status'] ?? 'scheduled';
        if ($status !== 'live' && $status !== 'finished') {
            return null;
        }

        $seed = (int) $match['id'];
        $live = $status === 'live';
        $minute = $live ? self::LIVE_MINUTE : null;
        $limit = $live ? self::LIVE_MINUTE : 90;
        $f = $live ? $limit / 90 : 1.0;
        $goalsHome = (int) ($match['score_home'] ?? 0);
        $goalsAway = (int) ($match['score_away'] ?? 0);

        $squads = [
            'home' => self::squad((string) $match['home'], $seed, 'home'),
            'away' => self::squad((string) $match['away'], $seed, 'away'),
        ];

        $events = self::events($seed, $squads, $goalsHome, $goalsAway, $limit, $live);
        $lineups = self::lineups($seed, $squads, $events, $goalsHome, $goalsAway);

        $bestPlayers = [
            'home' => self::bestPlayers($lineups['home']),
            'away' => self::bestPlayers($lineups['away']),
        ];
        unset($lineups['home']['all'], $lineups['away']['all']);

        return [
            'minute' => $minute,
            'events' => $events,
            'stats' => self::stats($seed, $f, $live, $goalsHome, $goalsAway, $events, $lineups),
            'best_players' => $bestPlayers,
            'lineups' => $lineups,
        ];
    }

    private static function rnd(int $seed, string $key, int $min, int $max): int
    {
        return $min + (crc32($seed.'|'.$key) % ($max - $min + 1));
    }

    /**
     * @return array{formation: string, players: list<array{number: int, name: string, line: string}>, bench: list<array{number: int, name: string, line: string}>, reserves: list<array{number: int, name: string, line: string}>, coach: string}
     */
    private static function squad(string $team, int $seed, string $side): array
    {
        $catalog = self::SQUADS[$team] ?? null;
        $formation = $catalog['formation'] ?? self::FORMATIONS[self::rnd($seed, "form-$side", 0, count(self::FORMATIONS) - 1)];
        $names = $catalog['players'] ?? array_map(
            static fn (int $i): string => 'Игрок '.($i + 1),
            range(0, 13),
        );

        $lines = array_map('intval', explode('-', $formation));
        $lineOf = ['gk'];
        foreach ($lines as $idx => $count) {
            $type = $idx === 0 ? 'def' : ($idx === count($lines) - 1 ? 'fwd' : 'mid');
            for ($i = 0; $i < $count; $i++) {
                $lineOf[] = $type;
            }
        }

        $players = [];
        foreach ($lineOf as $i => $line) {
            $players[] = ['number' => self::SHIRTS[$i], 'name' => $names[$i], 'line' => $line];
        }
        $bench = [];
        foreach ([11, 12, 13] as $k => $i) {
            $bench[] = ['number' => self::SHIRTS[$i], 'name' => $names[$i], 'line' => ['mid', 'fwd', 'def'][$k]];
        }

        $staff = self::STAFF[$team] ?? null;
        $reserves = [];
        foreach (['gk', 'def', 'mid', 'fwd'] as $k => $line) {
            $reserves[] = [
                'number' => self::RESERVE_SHIRTS[$k],
                'name' => $staff['reserves'][$k] ?? 'Запасной '.($k + 1),
                'line' => $line,
            ];
        }

        return [
            'formation' => $formation,
            'players' => $players,
            'bench' => $bench,
            'reserves' => $reserves,
            'coach' => $staff['coach'] ?? 'Главный тренер',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $squads
     * @return list<array<string, mixed>>
     */
    private static function events(int $seed, array $squads, int $goalsHome, int $goalsAway, int $limit, bool $live): array
    {
        $events = [];
        $used = ['home' => [], 'away' => []];

        foreach (['home' => $goalsHome, 'away' => $goalsAway] as $side => $goals) {
            $attackers = [];
            foreach ($squads[$side]['players'] as $idx => $p) {
                if ($p['line'] === 'fwd' || $p['line'] === 'mid') {
                    $attackers[] = $idx;
                }
            }
            $maxMinute = $live ? $limit - 2 : 90;
            for ($i = 0; $i < $goals; $i++) {
                $scorerIdx = $attackers[self::rnd($seed, "gs-$side-$i", 0, count($attackers) - 1)];
                $assistPool = array_values(array_filter($attackers, static fn (int $a): bool => $a !== $scorerIdx));
                $hasAssist = self::rnd($seed, "ga-$side-$i", 0, 3) !== 0;
                $assistIdx = $assistPool[self::rnd($seed, "gap-$side-$i", 0, count($assistPool) - 1)];
                $used[$side][] = $scorerIdx;
                if ($hasAssist) {
                    $used[$side][] = $assistIdx;
                }
                $events[] = [
                    'minute' => self::rnd($seed, "gm-$side-$i", 3, $maxMinute),
                    'side' => $side,
                    'type' => 'goal',
                    'player' => $squads[$side]['players'][$scorerIdx]['name'],
                    'assist' => $hasAssist ? $squads[$side]['players'][$assistIdx]['name'] : null,
                ];
            }

            $outfield = [];
            foreach ($squads[$side]['players'] as $idx => $p) {
                if ($p['line'] !== 'gk') {
                    $outfield[] = $idx;
                }
            }

            $yellows = self::rnd($seed, "yc-$side", 1, $live ? 2 : 3);
            for ($i = 0; $i < $yellows; $i++) {
                $idx = $outfield[self::rnd($seed, "yp-$side-$i", 0, count($outfield) - 1)];
                if (in_array($idx, $used[$side], true)) {
                    continue;
                }
                $used[$side][] = $idx;
                $events[] = [
                    'minute' => self::rnd($seed, "ym-$side-$i", 8, $limit),
                    'side' => $side,
                    'type' => 'yellow',
                    'player' => $squads[$side]['players'][$idx]['name'],
                ];
            }

            $subsCount = $live ? 1 : self::rnd($seed, "sc-$side", 2, 3);
            for ($i = 0; $i < $subsCount; $i++) {
                $minute = self::rnd($seed, "sm-$side-$i", 46, 82);
                $idx = $outfield[self::rnd($seed, "so-$side-$i", 0, count($outfield) - 1)];
                if ($minute > $limit || in_array($idx, $used[$side], true)) {
                    continue;
                }
                $used[$side][] = $idx;
                $events[] = [
                    'minute' => $minute,
                    'side' => $side,
                    'type' => 'sub',
                    'player_in' => $squads[$side]['bench'][$i]['name'],
                    'player_out' => $squads[$side]['players'][$idx]['name'],
                ];
            }
        }

        if (! $live && $seed % 5 === 2) {
            $side = $seed % 2 === 0 ? 'home' : 'away';
            $candidates = [];
            foreach ($squads[$side]['players'] as $idx => $p) {
                if ($p['line'] !== 'gk' && ! in_array($idx, $used[$side], true)) {
                    $candidates[] = $idx;
                }
            }
            $events[] = [
                'minute' => self::rnd($seed, 'rm', 55, 88),
                'side' => $side,
                'type' => 'red',
                'player' => $squads[$side]['players'][$candidates[self::rnd($seed, 'rp', 0, count($candidates) - 1)]]['name'],
            ];
        }

        usort($events, static fn (array $a, array $b): int => [$a['minute'], $a['side']] <=> [$b['minute'], $b['side']]);

        $home = 0;
        $away = 0;
        foreach ($events as &$event) {
            if ($event['type'] === 'goal') {
                $event['side'] === 'home' ? $home++ : $away++;
                $event['score_home'] = $home;
                $event['score_away'] = $away;
            }
        }
        unset($event);

        return $events;
    }

    /**
     * @param  array<string, array<string, mixed>>  $squads
     * @param  list<array<string, mixed>>  $events
     * @return array<string, array<string, mixed>>
     */
    private static function lineups(int $seed, array $squads, array $events, int $goalsHome, int $goalsAway): array
    {
        $result = [];

        foreach (['home', 'away'] as $side) {
            $goalsFor = $side === 'home' ? $goalsHome : $goalsAway;
            $goalsAgainst = $side === 'home' ? $goalsAway : $goalsHome;
            $resultBonus = $goalsFor <=> $goalsAgainst;

            $rows = [];
            $build = static function (array $p, int $i) use ($seed, $side, $resultBonus, $events): array {
                $goals = 0;
                $assists = 0;
                $yellow = 0;
                $red = 0;
                $subOut = null;
                $subIn = null;
                foreach ($events as $e) {
                    if ($e['side'] !== $side) {
                        continue;
                    }
                    if ($e['type'] === 'goal') {
                        $goals += $e['player'] === $p['name'] ? 1 : 0;
                        $assists += ($e['assist'] ?? null) === $p['name'] ? 1 : 0;
                    } elseif ($e['type'] === 'yellow' && $e['player'] === $p['name']) {
                        $yellow++;
                    } elseif ($e['type'] === 'red' && $e['player'] === $p['name']) {
                        $red++;
                    } elseif ($e['type'] === 'sub') {
                        $subOut = $e['player_out'] === $p['name'] ? $e['minute'] : $subOut;
                        $subIn = $e['player_in'] === $p['name'] ? $e['minute'] : $subIn;
                    }
                }

                $rating = 6.0 + self::rnd($seed, "r-$side-$i", 0, 16) / 10
                    + $goals * 0.9 + $assists * 0.5 - $yellow * 0.2 - $red * 1.5 + $resultBonus * 0.2;

                return [
                    'number' => $p['number'],
                    'name' => $p['name'],
                    'line' => $p['line'],
                    'rating' => round(max(4.5, min(9.9, $rating)), 1),
                    'goals' => $goals,
                    'assists' => $assists,
                    'yellow' => $yellow,
                    'red' => $red,
                    'sub_out' => $subOut,
                    'sub_in' => $subIn,
                ];
            };

            foreach ($squads[$side]['players'] as $i => $p) {
                $rows[] = $build($p, $i);
            }
            $bench = [];
            foreach ($squads[$side]['bench'] as $i => $p) {
                $row = $build($p, 20 + $i);
                if ($row['sub_in'] !== null) {
                    $bench[] = $row;
                }
            }

            $substitutes = [];
            foreach (array_merge($squads[$side]['bench'], $squads[$side]['reserves']) as $p) {
                $subIn = null;
                foreach ($events as $e) {
                    if ($e['side'] === $side && $e['type'] === 'sub' && $e['player_in'] === $p['name']) {
                        $subIn = $e['minute'];
                    }
                }
                $substitutes[] = [
                    'number' => $p['number'],
                    'name' => $p['name'],
                    'line' => $p['line'],
                    'sub_in' => $subIn,
                ];
            }

            $played = array_merge($rows, $bench);
            $result[$side] = [
                'coach' => $squads[$side]['coach'],
                'substitutes' => $substitutes,
                'formation' => $squads[$side]['formation'],
                'rating' => round(array_sum(array_column($rows, 'rating')) / count($rows), 2),
                'players' => $rows,
                'bench' => $bench,
                'all' => $played,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $lineup
     * @return list<array<string, mixed>>
     */
    private static function bestPlayers(array $lineup): array
    {
        $labels = ['gk' => 'Вратарь', 'def' => 'Защитник', 'mid' => 'Полузащитник', 'fwd' => 'Нападающий'];
        $players = $lineup['all'];
        usort($players, static fn (array $a, array $b): int => $b['rating'] <=> $a['rating']);

        return array_map(static fn (array $p): array => [
            'number' => $p['number'],
            'name' => $p['name'],
            'position' => $labels[$p['line']],
            'rating' => $p['rating'],
            'sub_in' => $p['sub_in'],
        ], array_slice($players, 0, 3));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, array<string, mixed>>  $lineups
     * @return list<array<string, mixed>>
     */
    private static function stats(int $seed, float $f, bool $live, int $goalsHome, int $goalsAway, array $events, array $lineups): array
    {
        $count = static function (string $type, string $side) use ($events): int {
            return count(array_filter($events, static fn (array $e): bool => $e['type'] === $type && $e['side'] === $side));
        };
        $r = static fn (string $key, int $min, int $max): int => self::rnd($seed, $key, $min, $max);
        $scaled = static fn (string $key, int $min, int $max): int => (int) round($r($key, $min, $max) * $f);

        $goals = ['home' => $goalsHome, 'away' => $goalsAway];
        $opponent = ['home' => 'away', 'away' => 'home'];

        $possHome = max(38, min(62, 50 + ($goalsHome - $goalsAway) * 3 + $r('poss', -8, 8)));
        $poss = ['home' => $possHome, 'away' => 100 - $possHome];

        $xgScale = $live ? max($f, 0.3) : 1.0;
        $xg = [];
        $xgot = [];
        $onTarget = [];
        $off = [];
        $blocked = [];
        $shots = [];
        foreach (['home', 'away'] as $s) {
            $xg[$s] = round((0.35 + $r("xg-$s", 0, 90) / 100 + $goals[$s] * 0.4) * $xgScale, 2);
            $xgot[$s] = round($xg[$s] * $r("xgot-$s", 105, 140) / 100, 2);
            $onTarget[$s] = $goals[$s] + (int) round($r("sot-$s", 1, 5) * $f);
            $off[$s] = $scaled("soff-$s", 2, 7);
            $blocked[$s] = $scaled("sbl-$s", 1, 5);
            $shots[$s] = $onTarget[$s] + $off[$s] + $blocked[$s];
        }

        $diffXg = $xg['home'] - $xg['away'];
        $pHome = 1 / (1 + exp(-1.7 * $diffXg));
        $pDraw = 0.28 * exp(-abs($diffXg));
        $xpts = [
            'home' => round(3 * $pHome * (1 - $pDraw) + $pDraw, 2),
            'away' => round(3 * (1 - $pHome) * (1 - $pDraw) + $pDraw, 2),
        ];

        $pair = static function (callable $value) {
            return [$value('home'), $value('away')];
        };

        $row = static function (string $id, string $label, mixed $home, mixed $away, string $fmt = 'int', ?string $better = 'high', ?string $hint = null): array {
            return compact('id', 'label', 'home', 'away', 'fmt', 'better', 'hint');
        };

        $attacks = [];
        $dribbleAttempts = [];
        $duels = [];
        $tackles = [];
        $passes = [];
        $crossesTotal = [];
        $setPieceXg = [];
        $cornerXg = [];
        foreach (['home', 'away'] as $s) {
            $attacks[$s] = (int) round($r("att-$s", 60, 110) * $f * $poss[$s] / 50);
            $dribbleAttempts[$s] = $scaled("dr-$s", 8, 24);
            $duels[$s] = $scaled("du-$s", 35, 60);
            $tackles[$s] = $scaled("tk-$s", 8, 22);
            $passes[$s] = (int) round($r("ps-$s", 250, 480) * $f * $poss[$s] / 50);
            $crossesTotal[$s] = $scaled("cr-$s", 8, 26);
            $setPieceXg[$s] = round($xg[$s] * $r("sp-$s", 10, 35) / 100, 2);
            $cornerXg[$s] = round($setPieceXg[$s] * $r("spc-$s", 40, 70) / 100, 2);
        }

        $sections = [];

        $sections[] = [
            'id' => 'xg-expected',
            'title' => 'Ожидаемые очки',
            'column' => 'left',
            'rows' => [
                $row('xpts', 'Ожидаемые очки (xPTS)', $xpts['home'], $xpts['away'], 'dec', 'high', 'Сколько очков «заслужила» команда по качеству моментов'),
            ],
        ];

        $sections[] = [
            'id' => 'xg-attack',
            'title' => 'Атака · xG',
            'column' => 'left',
            'rows' => [
                $row('xg', 'Ожидаемые голы (xG)', $xg['home'], $xg['away'], 'dec', 'high', 'Суммарное качество созданных моментов'),
                $row('xg-over', 'Перевыполнение xG', round($goalsHome - $xg['home'], 2), round($goalsAway - $xg['away'], 2), 'signed', 'high', 'Голы минус xG'),
                $row('xgop', 'xG с игры (xGOP)', round($xg['home'] * 0.82, 2), round($xg['away'] * 0.82, 2), 'dec', 'high'),
                $row('npxg', 'xG без пенальти (npxG)', $xg['home'], $xg['away'], 'dec', 'high'),
                $row('xgot', 'xG в створ (xGOT)', $xgot['home'], $xgot['away'], 'dec', 'high', 'Качество ударов, пришедшихся в створ'),
            ],
        ];

        $sections[] = [
            'id' => 'xg-defense',
            'title' => 'Защита · xG',
            'column' => 'left',
            'rows' => [
                $row('xga', 'Допущенные xG (xGA)', $xg['away'], $xg['home'], 'dec', 'low', 'Сколько xG создал соперник'),
                $row('xgp', 'Предотвращённые xG (xGP)', round($xgot['away'] - $goalsAway, 2), round($xgot['home'] - $goalsHome, 2), 'signed', 'high', 'xG в створ соперника минус пропущенные голы'),
            ],
        ];

        $sections[] = [
            'id' => 'xg-setpieces',
            'title' => 'Стандарты · xG',
            'column' => 'left',
            'rows' => [
                $row('xgsp', 'xG со стандартов (xGSP)', $setPieceXg['home'], $setPieceXg['away'], 'dec', 'high'),
                $row('xgc', 'xG с угловых (xGC)', $cornerXg['home'], $cornerXg['away'], 'dec', 'high'),
                $row('xgfk', 'xG со штрафных (xGFK)', round($setPieceXg['home'] - $cornerXg['home'], 2), round($setPieceXg['away'] - $cornerXg['away'], 2), 'dec', 'high'),
                $row('xgpen', 'xG с пенальти (xGPen)', null, null, 'dec', null),
            ],
        ];

        $dribblePct = ['home' => $r('dp-home', 45, 70), 'away' => $r('dp-away', 45, 70)];
        $bigChances = [
            'home' => max($goalsHome, $scaled('big-home', 1, 4)),
            'away' => max($goalsAway, $scaled('big-away', 1, 4)),
        ];

        $sections[] = [
            'id' => 'attack',
            'title' => 'Атака',
            'column' => 'right',
            'rows' => [
                $row('goals-open', 'Голы из игры', $goalsHome, $goalsAway),
                $row('goals-pen', 'Голы с пенальти', null, null),
                $row('attacks', 'Атаки', ...$pair(fn ($s) => $attacks[$s])),
                $row('dangerous', 'Опасные атаки', (int) round($attacks['home'] * $r('da-home', 40, 65) / 100), (int) round($attacks['away'] * $r('da-away', 40, 65) / 100)),
                $row('big', 'Голевые моменты', $bigChances['home'], $bigChances['away']),
                $row('big-missed', 'Упущенные моменты', max(0, $bigChances['home'] - $goalsHome), max(0, $bigChances['away'] - $goalsAway), 'int', 'low'),
                $row('dribbles', 'Попытки дриблинга', ...$pair(fn ($s) => $dribbleAttempts[$s])),
                $row('dribble-pct', 'Успешный дриблинг', $dribblePct['home'], $dribblePct['away'], 'pct'),
                $row('corners', 'Угловые', $scaled('co-home', 2, 9), $scaled('co-away', 2, 9)),
                $row('free-kicks', 'Штрафные удары', $scaled('fk-home', 6, 14), $scaled('fk-away', 6, 14)),
            ],
        ];

        $inBox = [];
        foreach (['home', 'away'] as $s) {
            $inBox[$s] = (int) round($shots[$s] * $r("box-$s", 55, 75) / 100);
        }

        $sections[] = [
            'id' => 'shots',
            'title' => 'Удары',
            'column' => 'right',
            'rows' => [
                $row('shots', 'Удары', $shots['home'], $shots['away']),
                $row('on-target', 'В створ ворот', $onTarget['home'], $onTarget['away']),
                $row('woodwork', 'В каркас ворот', $live ? 0 : ($seed % 3 === 0 ? 1 : 0), $live ? 0 : ($seed % 4 === 0 ? 1 : 0)),
                $row('off-target', 'Мимо ворот', $off['home'], $off['away'], 'int', 'low'),
                $row('blocked', 'Заблокированные удары', $blocked['home'], $blocked['away'], 'int', 'low'),
                $row('in-box', 'Удары из штрафной', $inBox['home'], $inBox['away']),
                $row('out-box', 'Удары из-за штрафной', $shots['home'] - $inBox['home'], $shots['away'] - $inBox['away']),
            ],
        ];

        $accuracy = ['home' => $r('acc-home', 74, 91), 'away' => $r('acc-away', 74, 91)];
        $crossPct = ['home' => $r('cp-home', 22, 40), 'away' => $r('cp-away', 22, 40)];

        $sections[] = [
            'id' => 'passes',
            'title' => 'Передачи',
            'column' => 'left',
            'rows' => [
                $row('possession', 'Владение мячом', $poss['home'], $poss['away'], 'pct'),
                $row('passes', 'Передачи всего', $passes['home'], $passes['away']),
                $row('pass-acc', 'Точные передачи', $accuracy['home'], $accuracy['away'], 'pct'),
                $row('key-passes', 'Ключевые передачи', $scaled('kp-home', 4, 14), $scaled('kp-away', 4, 14)),
                $row('crosses', 'Всего кроссов', $crossesTotal['home'], $crossesTotal['away']),
                $row('cross-pct', 'Точные кроссы', $crossPct['home'], $crossPct['away'], 'pct'),
                $row('throw-ins', 'Вбрасывания', $scaled('ti-home', 10, 24), $scaled('ti-away', 10, 24)),
                $row('offsides', 'Офсайды', $scaled('of-home', 0, 4), $scaled('of-away', 0, 4), 'int', 'low'),
            ],
        ];

        $duelsWon = $duels;
        $tacklesWon = [];
        foreach (['home', 'away'] as $s) {
            $tacklesWon[$s] = (int) round($tackles[$s] * $r("tw-$s", 50, 75) / 100);
        }
        $groundWon = [
            'home' => (int) round($duelsWon['home'] * 0.72),
            'away' => (int) round($duelsWon['away'] * 0.72),
        ];

        $sections[] = [
            'id' => 'defense',
            'title' => 'Защита',
            'column' => 'right',
            'rows' => [
                $row('tackles', 'Отборы', $tackles['home'], $tackles['away']),
                $row('tackles-won', 'Отборы выигранные', $tacklesWon['home'], $tacklesWon['away']),
                $row('interceptions', 'Перехваты', $scaled('ic-home', 4, 14), $scaled('ic-away', 4, 14)),
                $row('saves', 'Сейвы вратаря', max(0, $onTarget['away'] - $goalsAway), max(0, $onTarget['home'] - $goalsHome)),
                $row('duels', 'Выиграно единоборств всего', $duelsWon['home'], $duelsWon['away']),
                $row('duels-ground', 'Выиграно единоборств на земле', $groundWon['home'], $groundWon['away']),
                $row('duels-air', 'Выиграно единоборств в воздухе', $duelsWon['home'] - $groundWon['home'], $duelsWon['away'] - $groundWon['away']),
                $row('fouls', 'Фолы', $scaled('fo-home', 7, 15), $scaled('fo-away', 7, 15), 'int', 'low'),
                $row('yellow', 'Жёлтые карточки', $count('yellow', 'home'), $count('yellow', 'away'), 'int', 'low'),
                $row('red', 'Красные карточки', $count('red', 'home'), $count('red', 'away'), 'int', 'low'),
            ],
        ];

        return $sections;
    }
}
