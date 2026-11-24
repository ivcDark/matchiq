<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Моковый турнир для страницы /tournaments/{id}: шапка, таблица, лучшие команды, вклад игроков и топ-списки.
 * Сейчас доступен один турнир — РПЛ (id = 1). Игроки в моках вымышленные.
 */
class MockTournament
{
    public const RPL_ID = 1;

    /** Сколько строк в каждом топ-списке игроков. */
    public const TOP_LIMIT = 10;

    /** «Скорострельные»: минимум минут на поле, чтобы попасть в список. */
    public const MIN_MINUTES_SCORING = 600;

    /** «Лучшие вратари»: минимум сыгранных минут. */
    public const MIN_MINUTES_GOALKEEPER = 900;

    /** «Лучшие по рейтингу»: минимум сыгранных матчей. */
    public const MIN_MATCHES_RATING = 8;

    /** Позиции игроков в моках: код → краткая подпись и полное название (подписи дублируются на фронте). */
    public const POSITIONS = ['GK' => 'ВР', 'DF' => 'ЗЩ', 'MF' => 'ПЗ', 'FW' => 'НАП'];

    /** Лига (id на странице матча) → id турнира. */
    private const LEAGUE_TOURNAMENTS = ['rpl' => self::RPL_ID];

    /**
     * Турнирные зоны: id → подпись и позиции (включительно).
     * Цвета полосы заданы в CSS по `data-zone`.
     */
    private const ZONES = [
        ['id' => 'ucl', 'label' => 'Лига чемпионов', 'from' => 1, 'to' => 2],
        ['id' => 'europa', 'label' => 'Лига Европы', 'from' => 3, 'to' => 4],
        ['id' => 'conference', 'label' => 'Лига конференций', 'from' => 5, 'to' => 5],
        ['id' => 'playoff', 'label' => 'Стыковые матчи', 'from' => 13, 'to' => 14],
        ['id' => 'relegation', 'label' => 'Вылет', 'from' => 15, 'to' => 16],
    ];

    /** Команда, В, Н, П, забито, пропущено, форма (слева старше, справа свежее). */
    private const TEAMS = [
        ['Зенит', 10, 4, 1, 30, 10, ['В', 'В', 'Н', 'В', 'В']],
        ['Краснодар', 9, 4, 2, 26, 12, ['В', 'Н', 'В', 'В', 'П']],
        ['Спартак', 9, 3, 3, 27, 15, ['П', 'В', 'В', 'В', 'Н']],
        ['ЦСКА', 8, 4, 3, 24, 14, ['В', 'Н', 'В', 'П', 'В']],
        ['Динамо', 7, 5, 3, 21, 15, ['Н', 'В', 'П', 'В', 'В']],
        ['Локомотив', 7, 4, 4, 20, 17, ['В', 'В', 'Н', 'Н', 'П']],
        ['Ростов', 6, 5, 4, 19, 18, ['П', 'В', 'Н', 'В', 'Н']],
        ['Рубин', 5, 6, 4, 17, 17, ['Н', 'П', 'В', 'Н', 'В']],
        ['Крылья Советов', 5, 5, 5, 17, 19, ['Н', 'В', 'П', 'Н', 'П']],
        ['Динамо Мх', 4, 6, 5, 16, 18, ['В', 'Н', 'П', 'В', 'Н']],
        ['Акрон', 4, 5, 6, 15, 20, ['П', 'Н', 'П', 'В', 'Н']],
        ['Оренбург', 3, 5, 7, 14, 21, ['Н', 'П', 'П', 'В', 'П']],
        ['Ахмат', 3, 4, 8, 14, 19, ['П', 'Н', 'В', 'П', 'П']],
        ['Пари НН', 2, 4, 9, 12, 22, ['П', 'П', 'Н', 'П', 'В']],
        ['Торпедо', 2, 3, 10, 11, 26, ['П', 'П', 'Н', 'П', 'П']],
        ['Сочи', 1, 3, 11, 9, 29, ['П', 'Н', 'П', 'П', 'П']],
    ];

    /** Фамилия И., команда, позиция, матчей, минут, голов, пенальти, передач, средняя оценка, сухих матчей. */
    private const PLAYERS = [
        ['Рахимов А.', 'Зенит', 'FW', 15, 1290, 12, 3, 4, 7.62, 0],
        ['Бортников И.', 'Зенит', 'MF', 15, 1320, 4, 0, 9, 7.48, 0],
        ['Лаптев С.', 'Зенит', 'DF', 14, 1260, 2, 0, 3, 7.10, 0],
        ['Миронов Д.', 'Зенит', 'GK', 15, 1350, 0, 0, 0, 7.21, 7],
        ['Тихонов П.', 'Зенит', 'FW', 4, 220, 3, 0, 0, 7.05, 0],
        ['Зорин А.', 'Зенит', 'GK', 3, 270, 0, 0, 0, 7.00, 2],
        ['Шарипов Р.', 'Краснодар', 'FW', 14, 1170, 10, 2, 3, 7.45, 0],
        ['Ковалёв Н.', 'Краснодар', 'MF', 15, 1275, 5, 1, 8, 7.38, 0],
        ['Дробышев Е.', 'Краснодар', 'DF', 13, 1170, 1, 0, 2, 6.95, 0],
        ['Тарасов О.', 'Краснодар', 'GK', 15, 1350, 0, 0, 0, 7.05, 6],
        ['Орлов В.', 'Спартак', 'FW', 15, 1245, 9, 1, 2, 7.20, 0],
        ['Гусев М.', 'Спартак', 'MF', 15, 1300, 3, 0, 7, 7.31, 0],
        ['Беляев К.', 'Спартак', 'FW', 12, 780, 6, 0, 3, 7.30, 0],
        ['Фёдоров П.', 'Спартак', 'GK', 15, 1350, 0, 0, 0, 7.08, 5],
        ['Ефимов Л.', 'ЦСКА', 'FW', 14, 1100, 8, 2, 1, 7.15, 0],
        ['Назаров Т.', 'ЦСКА', 'MF', 15, 1330, 2, 0, 6, 7.22, 0],
        ['Громов А.', 'ЦСКА', 'DF', 15, 1350, 3, 0, 1, 6.98, 0],
        ['Лебедев Ю.', 'ЦСКА', 'GK', 15, 1350, 0, 0, 0, 7.02, 5],
        ['Субботин Г.', 'Динамо', 'FW', 15, 1260, 7, 0, 2, 7.05, 0],
        ['Платонов Д.', 'Динамо', 'MF', 14, 1210, 3, 0, 6, 7.12, 0],
        ['Крутов Е.', 'Динамо', 'DF', 15, 1350, 1, 0, 2, 6.90, 0],
        ['Зайцев В.', 'Динамо', 'GK', 14, 1260, 0, 0, 0, 6.96, 4],
        ['Макаров Ф.', 'Локомотив', 'FW', 13, 1010, 7, 1, 3, 7.18, 0],
        ['Воронин С.', 'Локомотив', 'MF', 15, 1290, 2, 0, 5, 7.08, 0],
        ['Щукин Б.', 'Локомотив', 'DF', 14, 1260, 2, 0, 1, 6.85, 0],
        ['Ермаков И.', 'Локомотив', 'GK', 15, 1350, 0, 0, 0, 6.90, 4],
        ['Исаев Р.', 'Ростов', 'FW', 15, 1180, 6, 0, 1, 6.95, 0],
        ['Прохоров А.', 'Ростов', 'MF', 14, 1130, 3, 0, 5, 7.02, 0],
        ['Давыдов К.', 'Ростов', 'GK', 15, 1350, 0, 0, 0, 6.82, 3],
        ['Хасанов Т.', 'Рубин', 'FW', 14, 1090, 6, 2, 2, 6.92, 0],
        ['Мельников Ю.', 'Рубин', 'MF', 15, 1310, 1, 0, 6, 6.99, 0],
        ['Савин Н.', 'Рубин', 'GK', 14, 1260, 0, 0, 0, 6.88, 3],
        ['Дьячков О.', 'Крылья Советов', 'FW', 15, 1210, 6, 1, 2, 6.88, 0],
        ['Кузин П.', 'Крылья Советов', 'MF', 15, 1340, 2, 0, 4, 6.93, 0],
        ['Рябов Ю.', 'Крылья Советов', 'GK', 15, 1350, 0, 0, 0, 6.80, 3],
        ['Алиев Э.', 'Динамо Мх', 'FW', 13, 980, 5, 0, 1, 6.84, 0],
        ['Трофимов В.', 'Динамо Мх', 'MF', 15, 1330, 1, 0, 3, 6.80, 0],
        ['Носов А.', 'Динамо Мх', 'GK', 15, 1350, 0, 0, 0, 6.78, 3],
        ['Седов М.', 'Акрон', 'FW', 14, 1150, 5, 1, 0, 6.78, 0],
        ['Бирюков А.', 'Акрон', 'MF', 15, 1300, 2, 0, 4, 6.86, 0],
        ['Гришин В.', 'Акрон', 'GK', 15, 1350, 0, 0, 0, 6.70, 2],
        ['Власов Д.', 'Оренбург', 'FW', 15, 1290, 4, 0, 1, 6.70, 0],
        ['Еремин Л.', 'Оренбург', 'MF', 15, 1330, 1, 0, 3, 6.72, 0],
        ['Сухов Э.', 'Оренбург', 'GK', 14, 1260, 0, 0, 0, 6.65, 2],
        ['Магомедов З.', 'Ахмат', 'FW', 12, 840, 5, 1, 1, 6.87, 0],
        ['Юсупов Р.', 'Ахмат', 'MF', 14, 1200, 1, 0, 3, 6.76, 0],
        ['Попов М.', 'Ахмат', 'GK', 15, 1350, 0, 0, 0, 6.68, 2],
        ['Котов И.', 'Пари НН', 'FW', 14, 1100, 4, 1, 1, 6.66, 0],
        ['Баранов Н.', 'Пари НН', 'DF', 15, 1350, 0, 0, 1, 6.62, 0],
        ['Жданов К.', 'Пари НН', 'GK', 15, 1350, 0, 0, 0, 6.60, 2],
        ['Жуков А.', 'Торпедо', 'FW', 13, 990, 3, 0, 1, 6.55, 0],
        ['Левин С.', 'Торпедо', 'MF', 15, 1300, 1, 0, 2, 6.58, 0],
        ['Чернов П.', 'Торпедо', 'GK', 15, 1350, 0, 0, 0, 6.45, 1],
        ['Ширяев Б.', 'Сочи', 'FW', 14, 1180, 3, 0, 0, 6.40, 0],
        ['Волков Д.', 'Сочи', 'GK', 15, 1350, 0, 0, 0, 6.30, 1],
    ];

    /** Контексты статистики «Лучшие команды» и «Лучшие игроки»: все матчи, дома, в гостях. */
    public const CONTEXTS = ['overall', 'home', 'away'];

    /** Строк в каждой мини-таблице «Лучшие команды». */
    public const TEAM_TOP_LIMIT = 5;

    /** Строк в таблице «Лучшие игроки» (вклад в голы команды). */
    public const CONTRIBUTION_LIMIT = 10;

    /**
     * Командная статистика за все матчи: владение %, жёлтые, красные, борьба в воздухе %,
     * удары за игру, точность передач %, средняя оценка команды.
     * Хозяева / гости получаются детерминированным сдвигом (см. teamValue).
     */
    private const TEAM_STATS = [
        'Зенит' => [57.3, 34, 1, 51.8, 15.5, 84.8, 6.74],
        'Краснодар' => [54.9, 37, 1, 52.0, 15.3, 79.2, 6.75],
        'Спартак' => [57.9, 39, 2, 48.4, 16.2, 82.0, 6.77],
        'ЦСКА' => [52.2, 31, 1, 53.7, 16.9, 79.8, 6.71],
        'Динамо' => [56.7, 42, 1, 56.0, 15.4, 80.3, 6.68],
        'Локомотив' => [50.8, 36, 2, 50.2, 13.9, 77.4, 6.60],
        'Ростов' => [47.9, 41, 3, 55.4, 12.8, 75.1, 6.70],
        'Рубин' => [48.6, 44, 2, 53.7, 12.1, 74.6, 6.52],
        'Крылья Советов' => [49.3, 33, 1, 49.5, 13.2, 76.9, 6.48],
        'Динамо Мх' => [46.1, 50, 5, 54.2, 11.8, 73.2, 6.40],
        'Акрон' => [51.4, 42, 1, 47.6, 14.1, 79.2, 6.45],
        'Оренбург' => [44.7, 37, 2, 52.0, 11.3, 71.8, 6.38],
        'Ахмат' => [45.5, 42, 1, 46.9, 12.5, 72.6, 6.42],
        'Пари НН' => [53.1, 29, 0, 45.3, 14.6, 81.5, 6.36],
        'Торпедо' => [43.9, 35, 3, 50.6, 10.9, 70.3, 6.30],
        'Сочи' => [42.8, 38, 2, 44.1, 10.4, 69.5, 6.22],
    ];

    public static function idForLeague(?string $leagueId): ?int
    {
        return $leagueId !== null ? (self::LEAGUE_TOURNAMENTS[$leagueId] ?? null) : null;
    }

    /**
     * @return array<string, mixed>|null null — турнира с таким id нет
     */
    public static function find(int $id, ?CarbonInterface $date = null): ?array
    {
        if ($id !== self::RPL_ID) {
            return null;
        }

        $players = array_map(self::player(...), self::PLAYERS);

        return [
            'tournament' => self::header($date ?? Carbon::now()),
            'zones' => array_map(
                static fn (array $z) => ['id' => $z['id'], 'label' => $z['label'], 'range' => self::zoneRange($z)],
                self::ZONES,
            ),
            'standings' => self::standings(),
            'leaders' => [
                'scorers' => self::scorers($players),
                'assists' => self::assists($players),
                'fastScorers' => self::fastScorers($players),
                'goalkeepers' => self::goalkeepers($players),
                'minutes' => self::minutes($players),
                'ratings' => self::ratings($players),
            ],
            'teamStats' => self::teamStatsByContext(),
            'contribution' => self::contributionByContext($players),
            'thresholds' => [
                'minutes_scoring' => self::MIN_MINUTES_SCORING,
                'minutes_goalkeeper' => self::MIN_MINUTES_GOALKEEPER,
                'matches_rating' => self::MIN_MATCHES_RATING,
            ],
        ];
    }

    /**
     * Сезон чемпионата РПЛ идёт с июля по май; не путать с сезоном рейтинга (1 авг — 31 июл).
     *
     * @return array<string, mixed>
     */
    private static function header(CarbonInterface $date): array
    {
        $startYear = $date->month >= 7 ? $date->year : $date->year - 1;

        return [
            'id' => self::RPL_ID,
            'name' => 'РПЛ',
            'full_name' => 'Российская Премьер-Лига',
            'country' => 'Россия',
            'season' => sprintf('%d/%02d', $startYear, ($startYear + 1) % 100),
            'round' => 15,
            'rounds_total' => 30,
            'stage' => 'Регулярный чемпионат',
        ];
    }

    /**
     * @param  array{from: int, to: int}  $zone
     */
    private static function zoneRange(array $zone): string
    {
        return $zone['from'] === $zone['to']
            ? (string) $zone['from']
            : $zone['from'].'–'.$zone['to'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function standings(): array
    {
        $rows = array_map(static function (array $t): array {
            [$team, $wins, $draws, $losses, $gf, $ga, $form] = $t;

            return [
                'team' => $team,
                'played' => $wins + $draws + $losses,
                'wins' => $wins,
                'draws' => $draws,
                'losses' => $losses,
                'gf' => $gf,
                'ga' => $ga,
                'gd' => $gf - $ga,
                'pts' => $wins * 3 + $draws,
                'form' => $form,
            ];
        }, self::TEAMS);

        usort($rows, static fn (array $a, array $b) => [$b['pts'], $b['gd'], $b['gf']] <=> [$a['pts'], $a['gd'], $a['gf']]);

        foreach ($rows as $i => &$row) {
            $pos = $i + 1;
            $row = ['pos' => $pos] + $row + ['zone' => self::zoneFor($pos)];
        }
        unset($row);

        return $rows;
    }

    private static function zoneFor(int $pos): ?string
    {
        foreach (self::ZONES as $zone) {
            if ($pos >= $zone['from'] && $pos <= $zone['to']) {
                return $zone['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $p
     * @return array<string, mixed>
     */
    private static function player(array $p): array
    {
        return [
            'name' => $p[0],
            'team' => $p[1],
            'position' => $p[2],
            'matches' => $p[3],
            'minutes' => $p[4],
            'goals' => $p[5],
            'pens' => $p[6],
            'assists' => $p[7],
            'ga' => $p[5] + $p[7],
            'rating' => $p[8],
            'clean_sheets' => $p[9],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $players
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function top(array $players, callable $compare, array $keys, ?int $limit = self::TOP_LIMIT): array
    {
        usort($players, $compare);

        $rows = [];
        foreach ($limit === null ? $players : array_slice($players, 0, $limit) as $i => $p) {
            $rows[] = ['rank' => $i + 1] + array_intersect_key($p, array_flip($keys));
        }

        return $rows;
    }

    /**
     * Бомбардиры: все с голом или передачей; по умолчанию Г+П ↓, голы ↓, минуты ↑.
     * Отдаём весь список — таблица на странице сортируется по колонкам, показывает топ-10 текущей сортировки.
     */
    private static function scorers(array $players): array
    {
        $eligible = array_values(array_filter($players, static fn (array $p) => $p['ga'] > 0));

        return self::top(
            $eligible,
            static fn (array $a, array $b) => [$b['ga'], $b['goals'], $a['minutes']] <=> [$a['ga'], $a['goals'], $b['minutes']],
            ['name', 'team', 'position', 'matches', 'goals', 'pens', 'assists', 'ga'],
            null,
        );
    }

    /**
     * Ассистенты: передачи ↓, минуты ↑. Весь список — для сортировки на странице.
     */
    private static function assists(array $players): array
    {
        $eligible = array_values(array_filter($players, static fn (array $p) => $p['assists'] > 0));

        return self::top(
            $eligible,
            static fn (array $a, array $b) => [$b['assists'], $a['minutes']] <=> [$a['assists'], $b['minutes']],
            ['name', 'team', 'position', 'matches', 'assists'],
            null,
        );
    }

    /**
     * Скорострельные: голов на 90 минут ↓ (то же, что минут на 1 гол ↑);
     * в список — от MIN_MINUTES_SCORING минут и минимум 1 гол. Отдаём весь список для сортировки на странице.
     */
    private static function fastScorers(array $players): array
    {
        $eligible = array_values(array_filter(
            $players,
            static fn (array $p) => $p['goals'] > 0 && $p['minutes'] >= self::MIN_MINUTES_SCORING,
        ));

        $rows = array_map(static fn (array $p) => $p + [
            'minutes_per_goal' => (int) round($p['minutes'] / $p['goals']),
            'goals_per_90' => round($p['goals'] * 90 / $p['minutes'], 2),
        ], $eligible);

        return self::top(
            $rows,
            static fn (array $a, array $b) => [$a['minutes'] / $a['goals'], $b['goals']] <=> [$b['minutes'] / $b['goals'], $a['goals']],
            ['name', 'team', 'position', 'goals', 'minutes', 'minutes_per_goal', 'goals_per_90'],
            null,
        );
    }

    /**
     * Вратари: сухих на 90 минут ↓ (то же, что минут на 1 сухой ↑);
     * в список — от MIN_MINUTES_GOALKEEPER минут и минимум 1 сухой матч. Весь список — для сортировки на странице.
     */
    private static function goalkeepers(array $players): array
    {
        $eligible = array_values(array_filter(
            $players,
            static fn (array $p) => $p['position'] === 'GK'
                && $p['clean_sheets'] > 0
                && $p['minutes'] >= self::MIN_MINUTES_GOALKEEPER,
        ));

        $rows = array_map(static fn (array $p) => $p + [
            'minutes_per_clean_sheet' => (int) round($p['minutes'] / $p['clean_sheets']),
            'clean_sheets_per_90' => round($p['clean_sheets'] * 90 / $p['minutes'], 2),
        ], $eligible);

        return self::top(
            $rows,
            static fn (array $a, array $b) => [$a['minutes'] / $a['clean_sheets'], $b['clean_sheets'], $b['minutes']] <=> [$b['minutes'] / $b['clean_sheets'], $a['clean_sheets'], $a['minutes']],
            ['name', 'team', 'position', 'clean_sheets', 'minutes', 'minutes_per_clean_sheet', 'clean_sheets_per_90'],
            null,
        );
    }

    /**
     * Больше всех на поле: минуты ↓, матчи ↓.
     */
    private static function minutes(array $players): array
    {
        return self::top(
            $players,
            static fn (array $a, array $b) => [$b['minutes'], $b['matches']] <=> [$a['minutes'], $a['matches']],
            ['name', 'team', 'position', 'matches', 'minutes'],
            null,
        );
    }

    /**
     * Лучшие по рейтингу: средняя оценка ↓; в список — от MIN_MATCHES_RATING матчей.
     */
    private static function ratings(array $players): array
    {
        $eligible = array_values(array_filter(
            $players,
            static fn (array $p) => $p['matches'] >= self::MIN_MATCHES_RATING,
        ));

        return self::top(
            $eligible,
            static fn (array $a, array $b) => [$b['rating'], $b['matches']] <=> [$a['rating'], $a['matches']],
            ['name', 'team', 'position', 'matches', 'rating'],
            null,
        );
    }

    /**
     * Сдвиг «дом/гость» от 0 до 1, одинаковый при каждом запросе.
     */
    private static function spread(string $team, string $metric): float
    {
        return (crc32($team.'|'.$metric) % 1000) / 1000;
    }

    /**
     * Значение командной метрики в контексте: дома выше среднего, в гостях ниже.
     * Карточки делятся так, что дома + в гостях = всего.
     */
    private static function teamValue(string $team, string $metric, float|int $base, string $context): float|int
    {
        $d = self::spread($team, $metric);

        if (in_array($metric, ['yellow', 'red'], true)) {
            if ($context === 'overall') {
                return $base;
            }
            // Дома карточек чуть меньше: 38–50% от общего числа.
            $home = (int) round($base * (0.38 + 0.12 * $d));

            return $context === 'home' ? $home : $base - $home;
        }

        $shift = match ($metric) {
            'shots' => 0.4 + 1.2 * $d,
            'rating' => 0.03 + 0.12 * $d,
            default => 0.6 + 2.4 * $d,
        };
        $signed = match ($context) {
            'home' => $shift,
            'away' => -$shift,
            default => 0.0,
        };
        $digits = $metric === 'rating' ? 2 : 1;
        $value = round($base + $signed, $digits);

        return in_array($metric, ['possession', 'aerial', 'passing'], true) ? min($value, 99.0) : $value;
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private static function teamStatsByContext(): array
    {
        $result = [];
        foreach (self::CONTEXTS as $context) {
            $teams = [];
            foreach (self::TEAM_STATS as $team => [$possession, $yellow, $red, $aerial, $shots, $passing, $rating]) {
                $teams[] = [
                    'team' => $team,
                    'possession' => self::teamValue($team, 'possession', $possession, $context),
                    'yellow' => self::teamValue($team, 'yellow', $yellow, $context),
                    'red' => self::teamValue($team, 'red', $red, $context),
                    'aerial' => self::teamValue($team, 'aerial', $aerial, $context),
                    'shots' => self::teamValue($team, 'shots', $shots, $context),
                    'passing' => self::teamValue($team, 'passing', $passing, $context),
                    'rating' => self::teamValue($team, 'rating', $rating, $context),
                ];
            }

            $result[$context] = [
                'possession' => self::teamTop($teams, ['possession'], ['team', 'possession']),
                'aggression' => self::teamTop($teams, ['yellow', 'red'], ['team', 'yellow', 'red']),
                'aerial' => self::teamTop($teams, ['aerial'], ['team', 'aerial']),
                'shots' => self::teamTop($teams, ['shots'], ['team', 'shots']),
                'passing' => self::teamTop($teams, ['passing'], ['team', 'passing']),
                'rating' => self::teamTop($teams, ['rating'], ['team', 'rating']),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $teams
     * @param  list<string>  $sortBy  поля сортировки по убыванию
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function teamTop(array $teams, array $sortBy, array $keys): array
    {
        usort($teams, static function (array $a, array $b) use ($sortBy): int {
            foreach ($sortBy as $field) {
                if ($a[$field] != $b[$field]) {
                    return $b[$field] <=> $a[$field];
                }
            }

            return 0;
        });

        $rows = [];
        foreach (array_slice($teams, 0, self::TEAM_TOP_LIMIT) as $i => $t) {
            $rows[] = ['rank' => $i + 1] + array_intersect_key($t, array_flip($keys));
        }

        return $rows;
    }

    /**
     * Вклад в голы команды: голы игрока / голы команды (без передач), топ по голам в контексте.
     * Голы команды и игроков делятся на дома / в гостях одним коэффициентом команды; голы игрока не больше голов команды.
     *
     * @param  list<array<string, mixed>>  $players
     * @return array<string, list<array<string, mixed>>>
     */
    private static function contributionByContext(array $players): array
    {
        $teamGoals = [];
        foreach (self::TEAMS as $t) {
            $teamGoals[$t[0]] = $t[4];
        }

        $result = [];
        foreach (self::CONTEXTS as $context) {
            $rows = [];
            foreach ($players as $p) {
                $total = $teamGoals[$p['team']];
                $share = 0.5 + 0.14 * self::spread($p['team'], 'home_goals');
                $teamHome = (int) ceil($total * $share);
                $teamCtx = match ($context) {
                    'home' => $teamHome,
                    'away' => $total - $teamHome,
                    default => $total,
                };
                $playerHome = (int) floor($p['goals'] * $share);
                $playerCtx = match ($context) {
                    'home' => $playerHome,
                    'away' => $p['goals'] - $playerHome,
                    default => $p['goals'],
                };
                $playerCtx = min($playerCtx, $teamCtx);

                if ($playerCtx < 1) {
                    continue;
                }

                $rows[] = [
                    'name' => $p['name'],
                    'team' => $p['team'],
                    'goals' => $playerCtx,
                    'team_goals' => $teamCtx,
                    'share' => (int) round($playerCtx / $teamCtx * 100),
                    'minutes' => $p['minutes'],
                ];
            }

            usort($rows, static fn (array $a, array $b) => [$b['goals'], $b['share'], $a['minutes']] <=> [$a['goals'], $a['share'], $b['minutes']]);

            $result[$context] = [];
            foreach (array_slice($rows, 0, self::CONTRIBUTION_LIMIT) as $i => $r) {
                unset($r['minutes']);
                $result[$context][] = ['rank' => $i + 1] + $r;
            }
        }

        return $result;
    }
}
