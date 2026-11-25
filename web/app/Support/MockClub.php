<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Моковая страница клуба `/clubs/{id}`: место в таблице РПЛ, этап Кубка России, статистика по турнирам,
 * игроки и разбивка голов. Доступны все 16 клубов РПЛ из турнирных моков; цифры считаются из тех же матчей,
 * что и турнирная таблица (первый круг), поэтому страницы клуба и турнира не расходятся.
 * Состав — известные игроки из MockTournament плюс детерминированно сгенерированные (вымышленные) фамилии.
 */
class MockClub
{
    public const RPL_KEY = 'rpl';

    public const CUP_KEY = 'cup';

    /** Агрегат РПЛ + Кубок России: сумма матчей, голов, карточек, средние взвешены по числу матчей. */
    public const ALL_KEY = 'all';

    /** id клуба → название (как в турнирных моках). */
    public const TEAMS = [
        1 => 'Зенит',
        2 => 'Краснодар',
        3 => 'Спартак',
        4 => 'ЦСКА',
        5 => 'Динамо',
        6 => 'Локомотив',
        7 => 'Ростов',
        8 => 'Рубин',
        9 => 'Крылья Советов',
        10 => 'Динамо Мх',
        11 => 'Акрон',
        12 => 'Оренбург',
        13 => 'Ахмат',
        14 => 'Пари НН',
        15 => 'Торпедо',
        16 => 'Сочи',
    ];

    private const CITIES = [
        1 => 'Санкт-Петербург', 2 => 'Краснодар', 3 => 'Москва', 4 => 'Москва', 5 => 'Москва', 6 => 'Москва',
        7 => 'Ростов-на-Дону', 8 => 'Казань', 9 => 'Самара', 10 => 'Махачкала', 11 => 'Тольятти',
        12 => 'Оренбург', 13 => 'Грозный', 14 => 'Нижний Новгород', 15 => 'Москва', 16 => 'Сочи',
    ];

    /** Кубок России, путь РПЛ: группы по 4 клуба, двухкруговой турнир (6 матчей). Сыграно 4 тура. */
    private const CUP_GROUPS = [
        'A' => [1, 4, 8, 11],
        'B' => [2, 5, 7, 12],
        'C' => [3, 6, 9, 14],
        'D' => [10, 13, 15, 16],
    ];

    private const CUP_ROUNDS_PLAYED = 4;

    private const CUP_ROUNDS_TOTAL = 6;

    /** Пары тура группы по индексам клубов (двухкруговой: туры 4–6 — ответные 1–3). */
    private const CUP_PAIRINGS = [
        1 => [[0, 1], [2, 3]],
        2 => [[2, 0], [1, 3]],
        3 => [[0, 3], [1, 2]],
        4 => [[1, 0], [3, 2]],
        5 => [[0, 2], [3, 1]],
        6 => [[3, 0], [2, 1]],
    ];

    /** Из группы в плей-офф выходят два лучших клуба. */
    private const CUP_ADVANCE_PLACES = 2;

    private const MIN_SQUAD = ['GK' => 2, 'DF' => 6, 'MF' => 6, 'FW' => 4];

    private const SURNAMES = [
        'Аксёнов', 'Белов', 'Васильев', 'Герасимов', 'Денисов', 'Егоров', 'Журавлёв', 'Зимин', 'Исаков', 'Калинин',
        'Лазарев', 'Морозов', 'Новиков', 'Осипов', 'Панин', 'Рогов', 'Семёнов', 'Титов', 'Устинов', 'Фролов',
        'Хохлов', 'Цветков', 'Чистяков', 'Шестаков', 'Щербаков', 'Юдин', 'Яковлев', 'Абрамов', 'Блинов', 'Виноградов',
        'Голубев', 'Дементьев', 'Ершов', 'Жилин', 'Зуев', 'Игнатов', 'Комаров', 'Лисин', 'Мартынов', 'Никонов',
        'Орехов', 'Пестов', 'Родин', 'Сазонов', 'Тарасенко', 'Ульянов', 'Фомин', 'Харитонов', 'Чернышёв', 'Шилов',
        'Ястребов', 'Бобров', 'Гаврилов', 'Дорофеев', 'Елисеев', 'Захаров', 'Ильин', 'Кудрявцев', 'Ларин', 'Мухин',
    ];

    private const INITIALS = ['А.', 'Б.', 'В.', 'Г.', 'Д.', 'Е.', 'И.', 'К.', 'Л.', 'М.', 'Н.', 'О.', 'П.', 'Р.', 'С.', 'Т.', 'Ф.', 'Э.', 'Ю.'];

    /** Вес позиции в раздаче голов, передач, карточек и признания лучшим игроком матча. */
    private const POS_WEIGHTS = [
        'goals' => ['GK' => 0.0, 'DF' => 0.8, 'MF' => 2.5, 'FW' => 6.0],
        'assists' => ['GK' => 0.0, 'DF' => 1.5, 'MF' => 4.0, 'FW' => 3.0],
        'yellow' => ['GK' => 0.3, 'DF' => 3.0, 'MF' => 2.5, 'FW' => 1.5],
        'red' => ['GK' => 0.2, 'DF' => 3.0, 'MF' => 2.0, 'FW' => 1.0],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cupCache = null;

    public static function idForName(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $id = array_search($name, self::TEAMS, true);

        return $id === false ? null : $id;
    }

    public static function nameFor(int $id): ?string
    {
        return self::TEAMS[$id] ?? null;
    }

    /**
     * @return array<string, mixed>|null null — клуба с таким id нет
     */
    public static function find(int $id): ?array
    {
        $team = self::nameFor($id);
        if ($team === null) {
            return null;
        }

        $tournament = MockTournament::find(MockTournament::RPL_ID);
        $contexts = MockTournament::CONTEXTS;
        $rows = [];
        foreach ($contexts as $context) {
            $rows[$context] = collect($tournament['standingsByContext'][$context])->firstWhere('team', $team);
        }

        $rplMatches = [];
        $cupMatches = self::cupMatches($team);
        foreach ($contexts as $context) {
            $rplMatches[$context] = self::filterVenue(MockTournament::teamMatches($team), $context);
        }

        $squad = self::squad($id, $team, $rows['overall']);
        $cupBase = self::cupSquad($squad, $team, $cupMatches);
        $raw = [];
        foreach ([self::RPL_KEY => $squad, self::CUP_KEY => $cupBase] as $key => $base) {
            $split = $key === self::RPL_KEY
                ? self::splitContexts($base, $team, $rows)
                : self::splitCupContexts($base, $team, $cupMatches);
            $raw[$key] = ['overall' => $base] + $split;
        }
        $raw[self::ALL_KEY] = [];
        foreach ($contexts as $context) {
            $raw[self::ALL_KEY][$context] = self::mergePlayers($raw[self::RPL_KEY][$context], $raw[self::CUP_KEY][$context]);
        }
        $players = array_map(static fn (array $byContext) => array_map(self::visible(...), $byContext), $raw);

        $tag = static fn (array $matches, string $key): array => array_map(static fn (array $m) => $m + ['tournament' => $key], $matches);
        $goals = [];
        foreach ($contexts as $context) {
            $rpl = $tag($rplMatches[$context], self::RPL_KEY);
            $cup = $tag(self::filterVenue($cupMatches, $context), self::CUP_KEY);
            $goals[self::RPL_KEY][$context] = self::goalSplit($rpl, $team, self::RPL_KEY);
            $goals[self::CUP_KEY][$context] = self::goalSplit($cup, $team, self::CUP_KEY);
            $goals[self::ALL_KEY][$context] = self::goalSplit([...$rpl, ...$cup], $team, self::ALL_KEY);
        }

        $fixtures = self::playedAndUpcoming($id, $team);

        return [
            'club' => [
                'id' => $id,
                'name' => $team,
                'city' => self::CITIES[$id],
                'tournament_id' => MockTournament::RPL_ID,
                'season' => $tournament['tournament']['season'],
                'round' => $tournament['tournament']['round'],
                'rounds_total' => $tournament['tournament']['rounds_total'],
            ],
            'standing' => self::standingSpot($team, $tournament),
            'cup' => self::cupStatus($id, $team),
            'tournaments' => [
                ['key' => self::RPL_KEY, 'name' => 'РПЛ'],
                ['key' => self::CUP_KEY, 'name' => 'Кубок России'],
                ['key' => self::ALL_KEY, 'name' => 'Все матчи'],
            ],
            'last_match' => $fixtures['played'][0] ?? null,
            'next_match' => $fixtures['upcoming'][0] ?? null,
            'calendar' => $fixtures['upcoming'],
            'results' => $fixtures['played'],
            'stats' => self::statsTable($team, $rows, $cupMatches),
            'goals' => $goals,
            'players' => $players,
        ];
    }

    /**
     * Место в таблице РПЛ и соседние строки (до пяти), чтобы видеть, кого клуб догоняет и кто дышит в спину.
     *
     * @param  array<string, mixed>  $tournament
     * @return array<string, mixed>
     */
    private static function standingSpot(string $team, array $tournament): array
    {
        $standings = $tournament['standings'];
        $index = (int) array_search($team, array_column($standings, 'team'), true);
        $row = $standings[$index];
        $zones = collect($tournament['zones'])->pluck('label', 'id');
        $start = max(0, min($index - 2, count($standings) - 5));

        $neighbors = [];
        foreach (array_slice($standings, $start, 5) as $r) {
            $neighbors[] = [
                'pos' => $r['pos'],
                'team' => $r['team'],
                'team_id' => $r['team_id'],
                'played' => $r['played'],
                'gd' => $r['gd'],
                'pts' => $r['pts'],
                'zone' => $r['zone'],
                'is_current' => $r['team'] === $team,
            ];
        }

        return [
            'tournament_id' => MockTournament::RPL_ID,
            'tournament' => $tournament['tournament']['name'],
            'position' => $row['pos'],
            'teams' => count($standings),
            'zone' => $row['zone'],
            'zone_label' => $row['zone'] ? $zones[$row['zone']] : null,
            'played' => $row['played'],
            'wins' => $row['wins'],
            'draws' => $row['draws'],
            'losses' => $row['losses'],
            'gf' => $row['gf'],
            'ga' => $row['ga'],
            'gd' => $row['gd'],
            'pts' => $row['pts'],
            'form' => $row['form'],
            'behind_leader' => $standings[0]['pts'] - $row['pts'],
            'neighbors' => $neighbors,
        ];
    }

    // ───────────────────────── Кубок России ─────────────────────────

    /**
     * Сыгранные матчи групп (туры 1–4), ближайший тур 5 и оставшиеся туры (`later`, для календаря клуба): те же результаты при каждом запросе.
     *
     * @return array{played: list<array<string, mixed>>, next: list<array<string, mixed>>, later: list<array<string, mixed>>, groups: array<string, list<string>>}
     */
    private static function cupFixtures(): array
    {
        if (self::$cupCache !== null) {
            return self::$cupCache;
        }

        $played = [];
        $next = [];
        $later = [];
        $groups = [];
        foreach (self::CUP_GROUPS as $letter => $ids) {
            $groups[$letter] = array_map(static fn (int $id) => self::TEAMS[$id], $ids);
            foreach (self::CUP_PAIRINGS as $round => $pairs) {
                foreach ($pairs as [$h, $a]) {
                    $home = $groups[$letter][$h];
                    $away = $groups[$letter][$a];
                    $fixture = ['group' => $letter, 'round' => $round, 'home' => $home, 'away' => $away];
                    if ($round <= self::CUP_ROUNDS_PLAYED) {
                        $played[] = $fixture + [
                            'home_goals' => self::cupGoals("$home|$away|$round|h"),
                            'away_goals' => self::cupGoals("$home|$away|$round|a"),
                        ];
                    } elseif ($round === self::CUP_ROUNDS_PLAYED + 1) {
                        $next[] = $fixture;
                    } else {
                        $later[] = $fixture;
                    }
                }
            }
        }

        return self::$cupCache = ['played' => $played, 'next' => $next, 'later' => $later, 'groups' => $groups];
    }

    private static function cupGoals(string $seed): int
    {
        $roll = crc32('cup|'.$seed) % 100;

        return $roll < 28 ? 0 : ($roll < 62 ? 1 : ($roll < 84 ? 2 : ($roll < 95 ? 3 : 4)));
    }

    /**
     * Сыгранные матчи клуба в группе Кубка: дома/в гостях, голы.
     *
     * @return list<array{round: int, venue: string, opponent: string, gf: int, ga: int}>
     */
    private static function cupMatches(string $team): array
    {
        $rows = [];
        foreach (self::cupFixtures()['played'] as $m) {
            foreach (['home' => 'away', 'away' => 'home'] as $side => $other) {
                if ($m[$side] === $team) {
                    $rows[] = [
                        'round' => $m['round'],
                        'venue' => $side,
                        'opponent' => $m[$other],
                        'gf' => $m[$side.'_goals'],
                        'ga' => $m[$other.'_goals'],
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Где команда в Кубке России: стадия, группа, место, очки, таблица группы и ближайший матч.
     *
     * @return array<string, mixed>
     */
    private static function cupStatus(int $id, string $team): array
    {
        $fixtures = self::cupFixtures();
        $letter = (string) collect(self::CUP_GROUPS)->search(static fn (array $ids) => in_array($id, $ids, true));

        $table = [];
        foreach ($fixtures['groups'][$letter] as $name) {
            $m = self::cupMatches($name);
            $wins = count(array_filter($m, static fn (array $r) => $r['gf'] > $r['ga']));
            $draws = count(array_filter($m, static fn (array $r) => $r['gf'] === $r['ga']));
            $gf = array_sum(array_column($m, 'gf'));
            $ga = array_sum(array_column($m, 'ga'));
            $table[] = [
                'team' => $name,
                'team_id' => self::idForName($name),
                'played' => count($m),
                'wins' => $wins,
                'draws' => $draws,
                'losses' => count($m) - $wins - $draws,
                'gf' => $gf,
                'ga' => $ga,
                'gd' => $gf - $ga,
                'pts' => $wins * 3 + $draws,
            ];
        }
        usort($table, static fn (array $a, array $b) => [$b['pts'], $b['gd'], $b['gf'], $a['team']] <=> [$a['pts'], $a['gd'], $a['gf'], $b['team']]);
        foreach ($table as $i => &$row) {
            $row = ['pos' => $i + 1] + $row;
        }
        unset($row);

        $own = collect($table)->firstWhere('team', $team);

        $next = null;
        foreach ($fixtures['next'] as $f) {
            if ($f['group'] === $letter && ($f['home'] === $team || $f['away'] === $team)) {
                $isHome = $f['home'] === $team;
                $opponent = $isHome ? $f['away'] : $f['home'];
                $next = [
                    'round' => $f['round'],
                    'venue' => $isHome ? 'home' : 'away',
                    'opponent' => $opponent,
                    'opponent_id' => self::idForName($opponent),
                    'date' => self::cupRoundDate($f['round'])->format('Y-m-d'),
                ];
            }
        }

        return [
            'name' => 'Кубок России',
            'path' => 'Путь РПЛ',
            'stage' => 'Групповой этап',
            'group' => $letter,
            'place' => $own['pos'],
            'played' => $own['played'],
            'rounds_played' => self::CUP_ROUNDS_PLAYED,
            'rounds_total' => self::CUP_ROUNDS_TOTAL,
            'wins' => $own['wins'],
            'draws' => $own['draws'],
            'losses' => $own['losses'],
            'gf' => $own['gf'],
            'ga' => $own['ga'],
            'pts' => $own['pts'],
            'advance_places' => self::CUP_ADVANCE_PLACES,
            'in_playoff_zone' => $own['pos'] <= self::CUP_ADVANCE_PLACES,
            'table' => $table,
            'next' => $next,
        ];
    }

    /** Туры 5–6 группы играют после зимнего перерыва РПЛ (март). */
    private static function cupRoundDate(int $round): Carbon
    {
        $now = Carbon::now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;

        return Carbon::create($startYear + 1, 3, 4)->addWeeks($round - self::CUP_ROUNDS_PLAYED - 1);
    }

    // ───────────────────────── Последний и следующий матч, календарь, результаты ─────────────────────────

    /**
     * Все матчи клуба по всем турнирам (РПЛ + Кубок России): сыгранные — со счётом, остальные — без.
     * Единый источник для «Последний/следующий матч», календаря и результатов. Ссылок на страницу матча нет.
     *
     * @return list<array<string, mixed>>
     */
    private static function clubFixtures(int $id, string $team): array
    {
        $fixtures = [];
        foreach (MockTournament::teamFixtures($team) as $m) {
            $fixtures[] = $m + [
                'tournament' => self::RPL_KEY,
                'tournament_name' => 'РПЛ',
                'stage' => $m['round'].'-й тур',
            ];
        }
        foreach (self::cupTeamFixtures($id, $team) as $m) {
            $fixtures[] = $m + ['tournament' => self::CUP_KEY, 'tournament_name' => 'Кубок России'];
        }

        return $fixtures;
    }

    /** @return array<string, mixed> */
    private static function fixtureRow(array $m): array
    {
        return [
            'tournament' => $m['tournament'],
            'tournament_name' => $m['tournament_name'],
            'stage' => $m['stage'],
            'date' => $m['date'],
            'time' => $m['time'],
            'venue' => $m['venue'],
            'opponent' => $m['opponent'],
            'opponent_id' => self::idForName($m['opponent']),
        ];
    }

    /** @return array<string, mixed> */
    private static function playedRow(array $m): array
    {
        return self::fixtureRow($m) + [
            'gf' => $m['gf'],
            'ga' => $m['ga'],
            'result' => [1 => 'В', 0 => 'Н', -1 => 'П'][$m['gf'] <=> $m['ga']],
        ];
    }

    /**
     * Сыгранные матчи (есть счёт) по убыванию даты и предстоящие по возрастанию, по всем турнирам клуба.
     * Последний матч = первый из сыгранных, следующий = первый из предстоящих.
     *
     * @return array{played: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    private static function playedAndUpcoming(int $id, string $team): array
    {
        $fixtures = self::clubFixtures($id, $team);
        $key = static fn (array $m): string => $m['date'].' '.$m['time'];
        $played = array_values(array_filter($fixtures, static fn (array $m) => $m['gf'] !== null));
        $upcoming = array_values(array_filter($fixtures, static fn (array $m) => $m['gf'] === null));
        usort($played, static fn (array $a, array $b) => $key($b) <=> $key($a));
        usort($upcoming, static fn (array $a, array $b) => $key($a) <=> $key($b));

        return [
            'played' => array_map(self::playedRow(...), $played),
            'upcoming' => array_map(self::fixtureRow(...), $upcoming),
        ];
    }

    /**
     * Матчи клуба в группе Кубка с датой и временем. Сыгранные туры 1–4 идут по вторникам после тура РПЛ
     * (группы A и B — после 3·N+3-го, C и D — после 3·N+2-го), чтобы в моках последним бывал и кубковый матч.
     * Дата тура 5 — после зимнего перерыва (`cupRoundDate`), у предстоящих голов нет.
     *
     * @return list<array<string, mixed>>
     */
    private static function cupTeamFixtures(int $id, string $team): array
    {
        $letter = (string) collect(self::CUP_GROUPS)->search(static fn (array $ids) => in_array($id, $ids, true));
        $now = Carbon::now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;
        $rows = [];

        foreach (self::cupFixtures() as $kind => $list) {
            if ($kind === 'groups') {
                continue;
            }
            foreach ($list as $m) {
                if ($m['group'] !== $letter || ($m['home'] !== $team && $m['away'] !== $team)) {
                    continue;
                }
                $isHome = $m['home'] === $team;
                $date = $kind === 'played'
                    ? MockTournament::saturday($startYear, 3 * $m['round'] + (in_array($letter, ['A', 'B'], true) ? 3 : 2))->addDays(3)
                    : self::cupRoundDate($m['round']);
                $rows[] = [
                    'round' => $m['round'],
                    'stage' => 'Группа '.$letter.' · '.$m['round'].'-й тур',
                    'date' => $date->format('Y-m-d'),
                    'time' => '19:00',
                    'venue' => $isHome ? 'home' : 'away',
                    'opponent' => $isHome ? $m['away'] : $m['home'],
                    'gf' => $kind === 'played' ? $m[$isHome ? 'home_goals' : 'away_goals'] : null,
                    'ga' => $kind === 'played' ? $m[$isHome ? 'away_goals' : 'home_goals'] : null,
                ];
            }
        }

        return $rows;
    }

    // ───────────────────────── Статистика по турнирам и голы ─────────────────────────

    /**
     * Таблица «по турнирам» с итоговой строкой, по контекстам.
     *
     * @param  array<string, array<string, mixed>>  $rows  строки таблицы РПЛ по контекстам
     * @param  list<array<string, mixed>>  $cupMatches
     * @return array<string, array{rows: list<array<string, mixed>>, total: array<string, mixed>}>
     */
    private static function statsTable(string $team, array $rows, array $cupMatches): array
    {
        $result = [];
        foreach (MockTournament::CONTEXTS as $context) {
            $line = MockTournament::teamStatLine($team, $context);
            $rpl = $rows[$context];
            $cup = self::filterVenue($cupMatches, $context);
            $cupApps = count($cup);

            $tableRows = [
                [
                    'key' => self::RPL_KEY,
                    'name' => 'РПЛ',
                    'apps' => $rpl['played'],
                    'goals' => $rpl['gf'],
                    'conceded' => $rpl['ga'],
                    'shots' => $line['shots'],
                    'yellow' => $line['yellow'],
                    'red' => $line['red'],
                    'possession' => $line['possession'],
                    'passing' => $line['passing'],
                    'aerial' => $line['aerial'],
                    'rating' => $line['rating'],
                ],
                [
                    'key' => self::CUP_KEY,
                    'name' => 'Кубок России',
                    'apps' => $cupApps,
                    'goals' => array_sum(array_column($cup, 'gf')),
                    'conceded' => array_sum(array_column($cup, 'ga')),
                    'shots' => null,
                    'yellow' => self::cupCardsIn($team, $cupMatches, $context, 'yellow'),
                    'red' => self::cupCardsIn($team, $cupMatches, $context, 'red'),
                    'possession' => null,
                    'passing' => null,
                    'aerial' => null,
                    'rating' => null,
                ],
            ];

            $result[$context] = ['rows' => $tableRows, 'total' => self::statsTotal($tableRows)];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function statsTotal(array $rows): array
    {
        $average = static function (string $field, int $digits) use ($rows): ?float {
            $set = array_filter($rows, static fn (array $r) => $r[$field] !== null && $r['apps'] > 0);
            $weight = array_sum(array_column($set, 'apps'));

            return $weight === 0
                ? null
                : round(array_sum(array_map(static fn (array $r) => $r[$field] * $r['apps'], $set)) / $weight, $digits);
        };

        return [
            'key' => 'total',
            'name' => 'Всего / среднее',
            'apps' => array_sum(array_column($rows, 'apps')),
            'goals' => array_sum(array_column($rows, 'goals')),
            'conceded' => array_sum(array_column($rows, 'conceded')),
            'shots' => $average('shots', 1),
            'yellow' => array_sum(array_column($rows, 'yellow')),
            'red' => array_sum(array_column($rows, 'red')),
            'possession' => $average('possession', 1),
            'passing' => $average('passing', 1),
            'aerial' => $average('aerial', 1),
            'rating' => $average('rating', 2),
        ];
    }

    /**
     * Карточки в Кубке по контексту: дома считаются от доли домашних матчей, гости — остаток (дома + гости = всего).
     *
     * @param  list<array<string, mixed>>  $cupMatches  все матчи группы
     */
    private static function cupCardsIn(string $team, array $cupMatches, string $context, string $type): int
    {
        $total = self::cupCards($team, count($cupMatches), $type);
        if ($context === 'overall' || $cupMatches === []) {
            return $total;
        }
        $home = (int) round($total * count(self::filterVenue($cupMatches, 'home')) / count($cupMatches));

        return $context === 'home' ? $home : $total - $home;
    }

    /** Карточки клуба в Кубке: от числа матчей, с небольшим разбросом по клубу. */
    private static function cupCards(string $team, int $apps, string $type): int
    {
        if ($apps === 0) {
            return 0;
        }

        return $type === 'yellow'
            ? (int) round($apps * (1.3 + 1.2 * self::spread($team, 'cup-yellow')))
            : (int) (self::spread($team, 'cup-red') > 0.8 ? 1 : 0);
    }

    /**
     * Голы забитые / пропущенные и рынки (тоталы 1.5–3.5, обе забьют, сухой матч, открыли счёт) по списку матчей.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private static function goalSplit(array $matches, string $team, string $tournament): array
    {
        $n = count($matches);
        $scored = array_sum(array_column($matches, 'gf'));
        $conceded = array_sum(array_column($matches, 'ga'));
        $count = static fn (callable $test): int => count(array_filter($matches, $test));
        $per = static fn (int $goals): ?float => $n > 0 ? round($goals / $n, 2) : null;

        $market = static fn (string $key, string $title, string $left, string $right, int $leftCount) => [
            'key' => $key,
            'title' => $title,
            'left_label' => $left,
            'right_label' => $right,
            'left' => $leftCount,
            'right' => $n - $leftCount,
            'left_pct' => $n > 0 ? (int) round($leftCount / $n * 100) : null,
            'right_pct' => $n > 0 ? 100 - (int) round($leftCount / $n * 100) : null,
        ];
        $total = static fn (array $m): int => $m['gf'] + $m['ga'];

        return [
            'matches' => $n,
            'scored' => $scored,
            'conceded' => $conceded,
            'scored_per_match' => $per($scored),
            'conceded_per_match' => $per($conceded),
            'markets' => [
                $market('total_1_5', '1.5', 'Менее', 'Более', $count(static fn (array $m) => $total($m) <= 1)),
                $market('total_2_5', '2.5', 'Менее', 'Более', $count(static fn (array $m) => $total($m) <= 2)),
                $market('total_3_5', '3.5', 'Менее', 'Более', $count(static fn (array $m) => $total($m) <= 3)),
                $market('btts', 'Обе забьют', 'Да', 'Нет', $count(static fn (array $m) => $m['gf'] > 0 && $m['ga'] > 0)),
                $market('clean_sheet', 'Сухой матч', 'Да', 'Нет', $count(static fn (array $m) => $m['ga'] === 0)),
                $market('scored', 'Открыли счёт', 'Да', 'Нет', $count(static fn (array $m) => self::openedScoring($m, $team, $tournament))),
            ],
        ];
    }

    /**
     * Открыл ли клуб счёт в матче. В моках нет минут первого гола, поэтому исход стабильно выводится из результата:
     * без забитых голов — нет, без пропущенных — да, иначе по хэшу матча: победа ~70%, ничья 50%, поражение ~30%.
     *
     * @param  array<string, mixed>  $match
     */
    private static function openedScoring(array $match, string $team, string $tournament): bool
    {
        if ($match['gf'] === 0) {
            return false;
        }
        if ($match['ga'] === 0) {
            return true;
        }

        $chance = $match['gf'] <=> $match['ga'];
        $threshold = [-1 => 30, 0 => 50, 1 => 70][$chance];
        $roll = crc32(implode('|', ['first-goal', $match['tournament'] ?? $tournament, $team, $match['round'], $match['venue'], $match['opponent']])) % 100;

        return $roll < $threshold;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private static function filterVenue(array $matches, string $context): array
    {
        return $context === 'overall'
            ? $matches
            : array_values(array_filter($matches, static fn (array $m) => $m['venue'] === $context));
    }

    // ───────────────────────── Состав ─────────────────────────

    /**
     * Состав за сезон РПЛ: известные игроки турнирных моков + сгенерированные до 18 человек.
     * Голы и передачи сходятся с голами команды в таблице, карточки — с командной статистикой.
     *
     * @param  array<string, mixed>  $standing  строка клуба в общей таблице
     * @return list<array<string, mixed>>
     */
    private static function squad(int $id, string $team, array $standing): array
    {
        $played = $standing['played'];
        $maxMinutes = $played * 90;
        $line = MockTournament::teamStatLine($team, 'overall');

        $players = [];
        foreach (MockTournament::teamPlayers($team) as $p) {
            $players[] = [
                'name' => $p['name'],
                'position' => $p['position'],
                'apps' => $p['matches'],
                'minutes' => $p['minutes'],
                'goals' => $p['goals'],
                'assists' => $p['assists'],
                'rating' => $p['rating'],
                'known' => true,
            ];
        }

        $names = array_column($players, 'name');
        $counts = array_count_values(array_column($players, 'position'));
        $seed = 0;
        foreach (self::MIN_SQUAD as $position => $want) {
            for ($i = $counts[$position] ?? 0; $i < $want; $i++) {
                do {
                    $surname = self::SURNAMES[crc32("$id|$position|$i|".$seed++) % count(self::SURNAMES)];
                    $name = $surname.' '.self::INITIALS[crc32("$id|init|$surname") % count(self::INITIALS)];
                } while (in_array($name, $names, true));
                $names[] = $name;
                $players[] = [
                    'name' => $name,
                    'position' => $position,
                    'apps' => 0,
                    'minutes' => 0,
                    'goals' => 0,
                    'assists' => 0,
                    'rating' => 0.0,
                    'known' => false,
                ];
            }
        }

        $keys = array_keys($players);
        $filler = array_values(array_filter($keys, static fn (int $k) => ! $players[$k]['known']));
        $knownMinutes = static fn (bool $gk): int => (int) array_sum(array_map(
            static fn (array $p) => $p['known'] && ($p['position'] === 'GK') === $gk ? $p['minutes'] : 0,
            $players,
        ));

        $gkTarget = max($maxMinutes, $knownMinutes(true));
        $outfieldTarget = 10 * $maxMinutes;
        foreach ([true, false] as $gk) {
            $group = array_values(array_filter($filler, static fn (int $k) => ($players[$k]['position'] === 'GK') === $gk));
            $weights = [];
            foreach ($group as $k) {
                $weights[$k] = 0.4 + self::spread($players[$k]['name'], 'minutes');
            }
            $alloc = self::allocate(
                max(0, ($gk ? $gkTarget : $outfieldTarget) - $knownMinutes($gk)),
                $weights,
                array_fill_keys($group, $maxMinutes),
            );
            foreach ($alloc as $k => $minutes) {
                $players[$k]['minutes'] = $minutes;
            }
        }

        foreach ($filler as $k) {
            $p = &$players[$k];
            $p['apps'] = $p['minutes'] === 0
                ? 0
                : min($played, max((int) ceil($p['minutes'] / 90), (int) round($p['minutes'] / 80)));
            $p['rating'] = $p['apps'] === 0 ? 0.0 : round(
                $line['rating'] + (self::spread($p['name'], 'rating') - 0.45) * 0.55 - ($p['position'] === 'DF' ? 0.05 : 0.0),
                2,
            );
            unset($p);
        }

        $weightOf = static fn (int $k, string $metric): float => self::POS_WEIGHTS[$metric][$players[$k]['position']]
            * ($players[$k]['minutes'] / max(1, $maxMinutes) + 0.05)
            * (0.6 + self::spread($players[$k]['name'], $metric));

        $fillerGoals = max(0, $standing['gf'] - (int) array_sum(array_column(array_filter($players, static fn ($p) => $p['known']), 'goals')));
        foreach (self::allocate($fillerGoals, array_combine($filler, array_map(static fn (int $k) => $weightOf($k, 'goals'), $filler))) as $k => $v) {
            $players[$k]['goals'] = $v;
        }

        $knownAssists = (int) array_sum(array_column(array_filter($players, static fn ($p) => $p['known']), 'assists'));
        $assistTarget = max($knownAssists, (int) round($standing['gf'] * 0.74));
        foreach (self::allocate($assistTarget - $knownAssists, array_combine($filler, array_map(static fn (int $k) => $weightOf($k, 'assists'), $filler))) as $k => $v) {
            $players[$k]['assists'] = $v;
        }

        $active = array_values(array_filter($keys, static fn (int $k) => $players[$k]['apps'] > 0));
        foreach (['yellow' => $line['yellow'], 'red' => $line['red']] as $metric => $total) {
            $alloc = self::allocate((int) $total, array_combine($active, array_map(static fn (int $k) => $weightOf($k, $metric), $active)));
            foreach ($keys as $k) {
                $players[$k][$metric] = $alloc[$k] ?? 0;
            }
        }

        $momWeights = [];
        foreach ($active as $k) {
            $momWeights[$k] = max(0.0, $players[$k]['rating'] - 6.2) ** 2 * $players[$k]['apps'] + 0.01;
        }
        $mom = self::allocate((int) round($played * 0.6), $momWeights);

        $result = [];
        foreach ($keys as $k) {
            $p = $players[$k];
            $pos = $p['position'];
            $noise = static fn (string $metric): float => self::spread($p['name'], $metric);
            $starts = min($p['apps'], intdiv($p['minutes'], 80));
            $result[] = [
                'name' => $p['name'],
                'position' => $pos,
                'age' => 19 + crc32($p['name'].'|age') % 18,
                'height' => ['GK' => 186, 'DF' => 179, 'MF' => 174, 'FW' => 175][$pos] + crc32($p['name'].'|h') % 12,
                'weight' => ['GK' => 80, 'DF' => 73, 'MF' => 68, 'FW' => 70][$pos] + crc32($p['name'].'|w') % 11,
                'apps' => $p['apps'],
                'starts' => $starts,
                'subs' => $p['apps'] - $starts,
                'minutes' => $p['minutes'],
                'goals' => $p['goals'],
                'assists' => $p['assists'],
                'yellow' => $p['yellow'] ?? 0,
                'red' => $p['red'] ?? 0,
                'shots' => $pos === 'GK' ? null : round(['DF' => 0.5, 'MF' => 1.1, 'FW' => 2.1][$pos] * (0.55 + 0.9 * $noise('shots')) * $line['shots'] / 13, 1),
                'passing' => round(min(94.0, $line['passing'] + ['GK' => -9, 'DF' => 1.5, 'MF' => 0.5, 'FW' => -4.5][$pos] + ($noise('passing') - 0.5) * 7), 1),
                'aerial' => $pos === 'GK' ? null : round(['DF' => 2.6, 'MF' => 1.1, 'FW' => 1.7][$pos] * (0.6 + 0.8 * $noise('aerial')) * $line['aerial'] / 50, 1),
                'mom' => $mom[$k] ?? 0,
                'rating' => $p['apps'] > 0 ? $p['rating'] : null,
            ];
        }

        usort($result, static fn (array $a, array $b) => [$b['rating'] ?? 0, $b['minutes']] <=> [$a['rating'] ?? 0, $a['minutes']]);

        return $result;
    }

    /**
     * Состав в кубковых матчах: те же игроки, число выходов и голов масштабируется под матчи группы.
     *
     * @param  list<array<string, mixed>>  $squad
     * @param  list<array<string, mixed>>  $cupMatches
     * @return list<array<string, mixed>>
     */
    private static function cupSquad(array $squad, string $team, array $cupMatches): array
    {
        $n = count($cupMatches);
        $played = max(1, MockTournament::ROUNDS_PLAYED);
        $goals = array_sum(array_column($cupMatches, 'gf'));

        $keys = array_keys($squad);
        $cup = [];
        foreach ($squad as $k => $p) {
            $apps = $p['apps'] === 0
                ? 0
                : max(0, min($n, (int) round($p['apps'] * $n / $played + (self::spread($p['name'], 'cup-apps') - 0.5) * 1.6)));
            $cup[$k] = ['apps' => $apps, 'minutes' => $p['apps'] > 0 ? (int) round($p['minutes'] / $p['apps'] * $apps * 0.97) : 0];
        }
        $active = array_values(array_filter($keys, static fn (int $k) => $cup[$k]['apps'] > 0));

        $share = static function (int $total, string $metric) use ($squad, $cup, $active): array {
            $weights = [];
            foreach ($active as $k) {
                $p = $squad[$k];
                $base = match ($metric) {
                    'mom' => max(0.0, ($p['rating'] ?? 6.2) - 6.2) ** 2 + 0.05,
                    'yellow', 'red' => self::POS_WEIGHTS[$metric][$p['position']] + 0.05,
                    default => (self::POS_WEIGHTS[$metric][$p['position']] + 0.05) * (0.7 + self::spread($p['name'], "cup-$metric")),
                };
                $weights[$k] = $base * $cup[$k]['apps'];
            }

            return self::allocate($total, $weights);
        };

        $goalShare = $share($goals, 'goals');
        $assistShare = $share((int) round($goals * 0.74), 'assists');
        $yellowShare = $share(self::cupCards($team, $n, 'yellow'), 'yellow');
        $redShare = $share(self::cupCards($team, $n, 'red'), 'red');
        $momShare = $share((int) round($n * 0.6), 'mom');

        $result = [];
        foreach ($squad as $k => $p) {
            $apps = $cup[$k]['apps'];
            $starts = min($apps, intdiv($cup[$k]['minutes'], 80));
            $drift = static fn (string $metric): float => (self::spread($p['name'], "cup-$metric") - 0.5) * 2;
            $result[] = [
                'apps' => $apps,
                'starts' => $starts,
                'subs' => $apps - $starts,
                'minutes' => $cup[$k]['minutes'],
                'goals' => $goalShare[$k] ?? 0,
                'assists' => $assistShare[$k] ?? 0,
                'yellow' => $yellowShare[$k] ?? 0,
                'red' => $redShare[$k] ?? 0,
                'mom' => $momShare[$k] ?? 0,
                'shots' => $p['shots'] === null ? null : round(max(0.0, $p['shots'] * (1 + 0.15 * $drift('shots'))), 1),
                'passing' => round(min(95.0, $p['passing'] + 1.5 * $drift('passing')), 1),
                'aerial' => $p['aerial'] === null ? null : round(max(0.0, $p['aerial'] * (1 + 0.15 * $drift('aerial'))), 1),
                'rating' => $apps > 0 ? round($p['rating'] + 0.12 * $drift('rating'), 2) : null,
            ] + $p;
        }

        return $result;
    }

    /**
     * Хозяева / гости для РПЛ: числа делятся так, что дома + в гостях = всего, а суммы по команде совпадают с таблицей.
     *
     * @param  list<array<string, mixed>>  $base
     * @param  array<string, array<string, mixed>>  $rows  строки таблицы РПЛ по контекстам
     * @return array{home: list<array<string, mixed>>, away: list<array<string, mixed>>}
     */
    private static function splitContexts(array $base, string $team, array $rows): array
    {
        $totalGoals = max(1, $rows['overall']['gf']);
        $assistsTotal = (int) array_sum(array_column($base, 'assists'));
        $line = [
            'home' => MockTournament::teamStatLine($team, 'home'),
            'away' => MockTournament::teamStatLine($team, 'away'),
        ];

        return self::splitHomeAway($base, [
            'home_matches' => $rows['home']['played'],
            'away_matches' => $rows['away']['played'],
            'goals' => $rows['home']['gf'],
            'assists' => (int) round($assistsTotal * $rows['home']['gf'] / $totalGoals),
            'yellow' => $line['home']['yellow'],
            'red' => $line['home']['red'],
            'mom' => (int) round(array_sum(array_column($base, 'mom')) * $rows['home']['played'] / max(1, $rows['overall']['played'])),
        ]);
    }

    /**
     * Хозяева / гости для Кубка по сыгранным матчам группы.
     *
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $cupMatches
     * @return array{home: list<array<string, mixed>>, away: list<array<string, mixed>>}
     */
    private static function splitCupContexts(array $base, string $team, array $cupMatches): array
    {
        $home = self::filterVenue($cupMatches, 'home');
        $away = self::filterVenue($cupMatches, 'away');
        $n = max(1, count($cupMatches));
        $homeGoals = array_sum(array_column($home, 'gf'));
        $totalGoals = max(1, array_sum(array_column($cupMatches, 'gf')));

        return self::splitHomeAway($base, [
            'home_matches' => count($home),
            'away_matches' => count($away),
            'goals' => $homeGoals,
            'assists' => (int) round(array_sum(array_column($base, 'assists')) * $homeGoals / $totalGoals),
            'yellow' => (int) round(array_sum(array_column($base, 'yellow')) * count($home) / $n),
            'red' => (int) round(array_sum(array_column($base, 'red')) * count($home) / $n),
            'mom' => (int) round(array_sum(array_column($base, 'mom')) * count($home) / $n),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $base  игроки за турнир целиком
     * @param  array<string, int>  $home  цели для домашних матчей: home_matches, away_matches, goals, assists, yellow, red, mom
     * @return array{home: list<array<string, mixed>>, away: list<array<string, mixed>>}
     */
    private static function splitHomeAway(array $base, array $home): array
    {
        $keys = array_keys($base);
        $parts = [];
        foreach (['goals', 'assists', 'yellow', 'red', 'mom'] as $metric) {
            $weights = [];
            $caps = [];
            foreach ($keys as $k) {
                $weights[$k] = $base[$k][$metric] * (0.6 + 0.8 * self::spread($base[$k]['name'], "split-$metric"));
                $caps[$k] = $base[$k][$metric];
            }
            $parts[$metric] = self::allocate(min($home[$metric], (int) array_sum($caps)), $weights, $caps);
        }

        $homeMatches = $home['home_matches'];
        $awayMatches = $home['away_matches'];
        $sides = ['home' => [], 'away' => []];
        foreach ($base as $k => $p) {
            $apps = $p['apps'];
            $homeApps = $apps === 0 ? 0 : max(
                max(0, $apps - $awayMatches),
                min($apps, $homeMatches, (int) round($apps * $homeMatches / max(1, $homeMatches + $awayMatches) + (self::spread($p['name'], 'split-apps') - 0.5) * 1.2)),
            );
            $homeMinutes = $apps === 0 ? 0 : (int) round($p['minutes'] * $homeApps / $apps);

            foreach (['home' => 1, 'away' => -1] as $side => $sign) {
                $isHome = $side === 'home';
                $sideApps = $isHome ? $homeApps : $apps - $homeApps;
                $minutes = $isHome ? $homeMinutes : $p['minutes'] - $homeMinutes;
                $starts = min($sideApps, intdiv($minutes, 80));
                $pick = static fn (string $metric): int => $isHome ? $parts[$metric][$k] : $p[$metric] - $parts[$metric][$k];
                $sides[$side][] = [
                    'apps' => $sideApps,
                    'starts' => $starts,
                    'subs' => $sideApps - $starts,
                    'minutes' => $minutes,
                    'goals' => $pick('goals'),
                    'assists' => $pick('assists'),
                    'yellow' => $pick('yellow'),
                    'red' => $pick('red'),
                    'mom' => $pick('mom'),
                    'shots' => $p['shots'] === null ? null : round(max(0.0, $p['shots'] * (1 + 0.12 * $sign)), 1),
                    'passing' => round(min(95.0, $p['passing'] + 1.2 * $sign), 1),
                    'aerial' => $p['aerial'] === null ? null : round(max(0.0, $p['aerial'] * (1 + 0.1 * $sign)), 1),
                    'rating' => $sideApps > 0 ? round(($p['rating'] ?? 6.5) + $sign * (0.05 + 0.1 * self::spread($p['name'], 'split-rating')), 2) : null,
                ] + $p;
            }
        }

        return $sides;
    }

    /**
     * Игроки РПЛ и Кубка в одном срезе: счётные показатели складываются, «за матч» (удары, пасы, воздух, рейтинг)
     * усредняются с весом по числу матчей. Списки идут в одном порядке (один состав).
     *
     * @param  list<array<string, mixed>>  $rpl
     * @param  list<array<string, mixed>>  $cup
     * @return list<array<string, mixed>>
     */
    private static function mergePlayers(array $rpl, array $cup): array
    {
        $merged = [];
        foreach ($rpl as $k => $a) {
            $b = $cup[$k];
            $weight = $a['apps'] + $b['apps'];
            $average = static function (string $field, int $digits) use ($a, $b, $weight): ?float {
                $parts = array_filter([$a, $b], static fn (array $p) => $p[$field] !== null && $p['apps'] > 0);
                if ($weight === 0 || $parts === []) {
                    return null;
                }
                $apps = array_sum(array_column($parts, 'apps'));

                return round(array_sum(array_map(static fn (array $p) => $p[$field] * $p['apps'], $parts)) / $apps, $digits);
            };

            $merged[] = [
                'apps' => $weight,
                'starts' => $a['starts'] + $b['starts'],
                'subs' => $a['subs'] + $b['subs'],
                'minutes' => $a['minutes'] + $b['minutes'],
                'goals' => $a['goals'] + $b['goals'],
                'assists' => $a['assists'] + $b['assists'],
                'yellow' => $a['yellow'] + $b['yellow'],
                'red' => $a['red'] + $b['red'],
                'mom' => $a['mom'] + $b['mom'],
                'shots' => $average('shots', 1),
                'passing' => $a['apps'] + $b['apps'] === 0 ? $a['passing'] : $average('passing', 1),
                'aerial' => $average('aerial', 1),
                'rating' => $average('rating', 2),
            ] + $a;
        }

        return $merged;
    }

    /**
     * Только игроки, выходившие на поле в срезе; по убыванию оценки.
     *
     * @param  list<array<string, mixed>>  $players
     * @return list<array<string, mixed>>
     */
    private static function visible(array $players): array
    {
        $players = array_values(array_filter($players, static fn (array $p) => $p['apps'] > 0));
        usort($players, static fn (array $a, array $b) => [$b['rating'], $b['minutes'], $a['name']] <=> [$a['rating'], $a['minutes'], $b['name']]);

        return $players;
    }

    // ───────────────────────── Вспомогательное ─────────────────────────

    private static function spread(string $a, string $b): float
    {
        return (crc32($a.'|'.$b) % 1000) / 1000;
    }

    /**
     * Раздаёт целое `$total` по весам методом наибольших долей, не превышая лимиты.
     * Если лимитов не хватает на всю сумму, остаток не раздаётся.
     *
     * @param  array<int, float>  $weights  ключ → вес (нулевой вес — не получает)
     * @param  array<int, int>|null  $caps  ключ → максимум (по умолчанию без ограничений)
     * @return array<int, int>
     */
    private static function allocate(int $total, array $weights, ?array $caps = null): array
    {
        $out = array_fill_keys(array_keys($weights), 0);
        $cap = static fn (int $k): int => $caps[$k] ?? PHP_INT_MAX;
        $active = array_values(array_filter(array_keys($weights), static fn (int $k) => $weights[$k] > 0 && $cap($k) > 0));
        $left = $total;

        while ($left > 0 && $active !== []) {
            $sum = array_sum(array_map(static fn (int $k) => $weights[$k], $active));
            $before = $left;
            $given = 0;
            foreach ($active as $k) {
                $give = min((int) floor($before * $weights[$k] / $sum), $cap($k) - $out[$k]);
                $out[$k] += $give;
                $given += $give;
            }
            $left -= $given;
            $active = array_values(array_filter($active, static fn (int $k) => $out[$k] < $cap($k)));

            if ($given === 0) {
                usort($active, static fn (int $a, int $b) => [$weights[$b], $a] <=> [$weights[$a], $b]);
                foreach ($active as $k) {
                    if ($left <= 0) {
                        break;
                    }
                    $out[$k]++;
                    $left--;
                }
                $active = array_values(array_filter($active, static fn (int $k) => $out[$k] < $cap($k)));
            }
        }

        return $out;
    }
}
