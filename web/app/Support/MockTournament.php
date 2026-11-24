<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Моковый турнир для страницы /tournaments/{id}: шапка, матчи (таблица, форма, расписание, результаты), лучшие команды, вклад игроков и топ-списки.
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

    /** Сколько туров уже сыграно: тур 15 из 30 (16 команд, два круга). */
    public const ROUNDS_PLAYED = 15;

    public const ROUNDS_TOTAL = 30;

    /**
     * Результаты первого круга (туры 1–15): тур, хозяева, гости, голы хозяев, голы гостей.
     * Единственный источник турнирной таблицы, формы и результатов; второй круг (туры 16–30) —
     * те же пары с обратным полем, ещё не сыгран.
     */
    private const MATCHES = [
        [1, 'Зенит', 'Сочи', 3, 2],
        [1, 'Торпедо', 'Краснодар', 0, 2],
        [1, 'Спартак', 'Пари НН', 3, 1],
        [1, 'Ахмат', 'ЦСКА', 0, 1],
        [1, 'Динамо', 'Оренбург', 3, 3],
        [1, 'Акрон', 'Локомотив', 3, 1],
        [1, 'Ростов', 'Динамо Мх', 3, 1],
        [1, 'Крылья Советов', 'Рубин', 2, 2],
        [2, 'Торпедо', 'Зенит', 1, 4],
        [2, 'Пари НН', 'Сочи', 0, 0],
        [2, 'Ахмат', 'Краснодар', 1, 2],
        [2, 'Спартак', 'Оренбург', 2, 0],
        [2, 'Акрон', 'ЦСКА', 0, 1],
        [2, 'Динамо', 'Динамо Мх', 3, 2],
        [2, 'Крылья Советов', 'Локомотив', 3, 2],
        [2, 'Ростов', 'Рубин', 0, 0],
        [3, 'Зенит', 'Пари НН', 2, 0],
        [3, 'Ахмат', 'Торпедо', 4, 1],
        [3, 'Оренбург', 'Сочи', 3, 1],
        [3, 'Акрон', 'Краснодар', 0, 1],
        [3, 'Спартак', 'Динамо Мх', 3, 0],
        [3, 'Крылья Советов', 'ЦСКА', 0, 3],
        [3, 'Рубин', 'Динамо', 0, 0],
        [3, 'Локомотив', 'Ростов', 0, 0],
        [4, 'Ахмат', 'Зенит', 0, 1],
        [4, 'Пари НН', 'Оренбург', 0, 0],
        [4, 'Акрон', 'Торпедо', 2, 0],
        [4, 'Сочи', 'Динамо Мх', 0, 0],
        [4, 'Крылья Советов', 'Краснодар', 1, 1],
        [4, 'Спартак', 'Рубин', 1, 3],
        [4, 'Ростов', 'ЦСКА', 4, 1],
        [4, 'Динамо', 'Локомотив', 0, 0],
        [5, 'Зенит', 'Оренбург', 3, 0],
        [5, 'Акрон', 'Ахмат', 0, 0],
        [5, 'Пари НН', 'Динамо Мх', 1, 1],
        [5, 'Крылья Советов', 'Торпедо', 3, 4],
        [5, 'Сочи', 'Рубин', 3, 1],
        [5, 'Краснодар', 'Ростов', 3, 0],
        [5, 'Локомотив', 'Спартак', 4, 3],
        [5, 'ЦСКА', 'Динамо', 2, 1],
        [6, 'Акрон', 'Зенит', 1, 3],
        [6, 'Оренбург', 'Динамо Мх', 1, 4],
        [6, 'Крылья Советов', 'Ахмат', 1, 1],
        [6, 'Пари НН', 'Рубин', 4, 2],
        [6, 'Ростов', 'Торпедо', 0, 0],
        [6, 'Сочи', 'Локомотив', 0, 4],
        [6, 'Краснодар', 'Динамо', 0, 0],
        [6, 'ЦСКА', 'Спартак', 1, 3],
        [7, 'Зенит', 'Динамо Мх', 3, 0],
        [7, 'Крылья Советов', 'Акрон', 1, 1],
        [7, 'Оренбург', 'Рубин', 0, 1],
        [7, 'Ростов', 'Ахмат', 2, 1],
        [7, 'Локомотив', 'Пари НН', 1, 0],
        [7, 'Торпедо', 'Динамо', 0, 1],
        [7, 'ЦСКА', 'Сочи', 0, 0],
        [7, 'Краснодар', 'Спартак', 0, 0],
        [8, 'Крылья Советов', 'Зенит', 0, 1],
        [8, 'Динамо Мх', 'Рубин', 0, 0],
        [8, 'Ростов', 'Акрон', 2, 2],
        [8, 'Оренбург', 'Локомотив', 0, 1],
        [8, 'Динамо', 'Ахмат', 3, 1],
        [8, 'ЦСКА', 'Пари НН', 3, 0],
        [8, 'Торпедо', 'Спартак', 0, 0],
        [8, 'Краснодар', 'Сочи', 4, 1],
        [9, 'Зенит', 'Рубин', 0, 0],
        [9, 'Ростов', 'Крылья Советов', 0, 2],
        [9, 'Динамо Мх', 'Локомотив', 3, 0],
        [9, 'Динамо', 'Акрон', 2, 1],
        [9, 'ЦСКА', 'Оренбург', 2, 2],
        [9, 'Ахмат', 'Спартак', 0, 0],
        [9, 'Краснодар', 'Пари НН', 3, 0],
        [9, 'Торпедо', 'Сочи', 2, 0],
        [10, 'Ростов', 'Зенит', 1, 3],
        [10, 'Рубин', 'Локомотив', 2, 1],
        [10, 'Динамо', 'Крылья Советов', 2, 0],
        [10, 'Динамо Мх', 'ЦСКА', 0, 2],
        [10, 'Акрон', 'Спартак', 1, 0],
        [10, 'Краснодар', 'Оренбург', 2, 0],
        [10, 'Ахмат', 'Сочи', 4, 1],
        [10, 'Торпедо', 'Пари НН', 0, 0],
        [11, 'Зенит', 'Локомотив', 0, 0],
        [11, 'Динамо', 'Ростов', 0, 2],
        [11, 'Рубин', 'ЦСКА', 0, 2],
        [11, 'Спартак', 'Крылья Советов', 2, 1],
        [11, 'Динамо Мх', 'Краснодар', 0, 0],
        [11, 'Сочи', 'Акрон', 0, 1],
        [11, 'Торпедо', 'Оренбург', 0, 1],
        [11, 'Ахмат', 'Пари НН', 1, 0],
        [12, 'Динамо', 'Зенит', 1, 1],
        [12, 'Локомотив', 'ЦСКА', 0, 0],
        [12, 'Спартак', 'Ростов', 3, 0],
        [12, 'Рубин', 'Краснодар', 1, 3],
        [12, 'Сочи', 'Крылья Советов', 0, 1],
        [12, 'Динамо Мх', 'Торпедо', 1, 0],
        [12, 'Пари НН', 'Акрон', 4, 1],
        [12, 'Оренбург', 'Ахмат', 0, 0],
        [13, 'Зенит', 'ЦСКА', 0, 0],
        [13, 'Спартак', 'Динамо', 2, 1],
        [13, 'Локомотив', 'Краснодар', 1, 0],
        [13, 'Сочи', 'Ростов', 0, 2],
        [13, 'Рубин', 'Торпедо', 1, 0],
        [13, 'Пари НН', 'Крылья Советов', 0, 1],
        [13, 'Динамо Мх', 'Ахмат', 2, 0],
        [13, 'Оренбург', 'Акрон', 3, 0],
        [14, 'Спартак', 'Зенит', 3, 2],
        [14, 'ЦСКА', 'Краснодар', 3, 4],
        [14, 'Сочи', 'Динамо', 0, 2],
        [14, 'Локомотив', 'Торпедо', 4, 3],
        [14, 'Пари НН', 'Ростов', 1, 2],
        [14, 'Рубин', 'Ахмат', 4, 1],
        [14, 'Оренбург', 'Крылья Советов', 0, 1],
        [14, 'Динамо Мх', 'Акрон', 2, 2],
        [15, 'Краснодар', 'Зенит', 1, 4],
        [15, 'Сочи', 'Спартак', 1, 2],
        [15, 'ЦСКА', 'Торпедо', 3, 0],
        [15, 'Пари НН', 'Динамо', 1, 2],
        [15, 'Локомотив', 'Ахмат', 1, 0],
        [15, 'Оренбург', 'Ростов', 1, 1],
        [15, 'Рубин', 'Акрон', 0, 0],
        [15, 'Динамо Мх', 'Крылья Советов', 0, 0],
    ];

    /** Слоты матчей внутри тура: сдвиг в днях от субботы и время начала. */
    private const SLOTS = [
        [-1, '19:00'], [0, '12:30'], [0, '15:00'], [0, '17:30'],
        [0, '20:00'], [1, '12:30'], [1, '15:00'], [1, '17:30'],
    ];

    /** Доля собственных показателей команды в прогнозе (остальное — среднее по лиге): сглаживает выборку из 15 матчей. */
    private const RATE_WEIGHT = 0.55;

    /** Подписи форм: результат с точки зрения команды. */
    private const RESULT_LETTERS = ['win' => 'В', 'draw' => 'Н', 'loss' => 'П'];

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

        $date ??= Carbon::now();
        $startYear = self::startYear($date);
        $matches = self::allMatches($startYear);
        $played = array_values(array_filter($matches, static fn (array $m) => $m['home_goals'] !== null));

        $players = array_map(self::player(...), self::PLAYERS);
        $byContext = [];
        foreach (self::CONTEXTS as $context) {
            $byContext[$context] = self::standings($played, $context);
        }
        $standings = $byContext['overall'];

        return [
            'tournament' => self::header($startYear),
            'zones' => array_map(
                static fn (array $z) => ['id' => $z['id'], 'label' => $z['label'], 'range' => self::zoneRange($z)],
                self::ZONES,
            ),
            'standings' => $standings,
            'standingsByContext' => $byContext,
            'teamForm' => self::teamForm($played, $standings),
            'schedule' => self::schedule($matches, $standings),
            'results' => self::results($played),
            'attackDefense' => self::attackDefense($standings),
            'leaders' => [
                'scorers' => self::scorers($players),
                'assists' => self::assists($players),
                'fastScorers' => self::fastScorers($players),
                'goalkeepers' => self::goalkeepers($players),
                'minutes' => self::minutes($players),
                'ratings' => self::ratings($players),
            ],
            'teamStats' => self::teamStatsByContext(),
            'contribution' => self::contributionByContext($players, $standings),
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
    private static function header(int $startYear): array
    {
        return [
            'id' => self::RPL_ID,
            'name' => 'РПЛ',
            'full_name' => 'Российская Премьер-Лига',
            'country' => 'Россия',
            'season' => sprintf('%d/%02d', $startYear, ($startYear + 1) % 100),
            'round' => self::ROUNDS_PLAYED,
            'next_round' => self::ROUNDS_PLAYED + 1,
            'rounds_total' => self::ROUNDS_TOTAL,
            'stage' => 'Регулярный чемпионат',
        ];
    }

    private static function startYear(CarbonInterface $date): int
    {
        return $date->month >= 7 ? $date->year : $date->year - 1;
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
     * Все 30 туров: сыгранные (первый круг, с голами) и предстоящие (второй круг, голы null),
     * с датой и временем начала. Даты привязаны к сезону: зимний перерыв между 18 и 19 турами.
     *
     * @return list<array<string, mixed>>
     */
    private static function allMatches(int $startYear): array
    {
        $fixtures = [];
        foreach (self::MATCHES as [$round, $home, $away, $homeGoals, $awayGoals]) {
            $fixtures[] = [
                'round' => $round,
                'home' => $home,
                'away' => $away,
                'home_goals' => $homeGoals,
                'away_goals' => $awayGoals,
            ];
        }
        foreach (self::MATCHES as [$round, $home, $away]) {
            $fixtures[] = [
                'round' => $round + self::ROUNDS_PLAYED,
                'home' => $away,
                'away' => $home,
                'home_goals' => null,
                'away_goals' => null,
            ];
        }

        $slots = [];
        foreach ($fixtures as &$fixture) {
            $slot = $slots[$fixture['round']] = ($slots[$fixture['round']] ?? -1) + 1;
            [$offset, $time] = self::SLOTS[$slot % count(self::SLOTS)];
            $fixture['date'] = self::saturday($startYear, $fixture['round'])->addDays($offset)->format('Y-m-d');
            $fixture['time'] = $time;
        }
        unset($fixture);

        return $fixtures;
    }

    private static function saturday(int $startYear, int $round): CarbonInterface
    {
        return $round <= 18
            ? Carbon::create($startYear, 8, 14)->next(CarbonInterface::SATURDAY)->addWeeks($round - 1)
            : Carbon::create($startYear + 1, 3, 5)->next(CarbonInterface::SATURDAY)->addWeeks($round - 19);
    }

    /**
     * Турнирная таблица по сыгранным матчам: «overall» — все, «home» — только домашние, «away» — только гостевые.
     * Форма — результаты последних 5 матчей в этом контексте (слева старше, справа свежее).
     * Зоны (еврокубки, вылет) размечены только в общей таблице.
     *
     * @param  list<array<string, mixed>>  $played  в хронологическом порядке
     * @return list<array<string, mixed>>
     */
    private static function standings(array $played, string $context = 'overall'): array
    {
        $stats = [];
        foreach ($played as $m) {
            foreach (['home' => 'away', 'away' => 'home'] as $side => $other) {
                if ($context !== 'overall' && $context !== $side) {
                    continue;
                }
                $gf = $m[$side.'_goals'];
                $ga = $m[$other.'_goals'];
                $s = &$stats[$m[$side]];
                $s ??= ['wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'form' => []];
                $s['gf'] += $gf;
                $s['ga'] += $ga;
                $result = self::resultOf($gf, $ga);
                $s[['В' => 'wins', 'Н' => 'draws', 'П' => 'losses'][$result]]++;
                $s['form'][] = $result;
                unset($s);
            }
        }

        $rows = [];
        foreach ($stats as $team => $s) {
            $rows[] = [
                'team' => $team,
                'played' => $s['wins'] + $s['draws'] + $s['losses'],
                'wins' => $s['wins'],
                'draws' => $s['draws'],
                'losses' => $s['losses'],
                'gf' => $s['gf'],
                'ga' => $s['ga'],
                'gd' => $s['gf'] - $s['ga'],
                'pts' => $s['wins'] * 3 + $s['draws'],
                'form' => array_slice($s['form'], -5),
            ];
        }

        usort($rows, static fn (array $a, array $b) => [$b['pts'], $b['gd'], $b['gf'], $a['team']] <=> [$a['pts'], $a['gd'], $a['gf'], $b['team']]);

        foreach ($rows as $i => &$row) {
            $pos = $i + 1;
            $row = ['pos' => $pos] + $row + ['zone' => $context === 'overall' ? self::zoneFor($pos) : null];
        }
        unset($row);

        return $rows;
    }

    /**
     * Результат команды: В / Н / П по забитым и пропущенным.
     */
    private static function resultOf(int $gf, int $ga): string
    {
        return $gf > $ga ? self::RESULT_LETTERS['win'] : ($gf < $ga ? self::RESULT_LETTERS['loss'] : self::RESULT_LETTERS['draw']);
    }

    /**
     * Форма всех команд по всем сыгранным матчам в хронологическом порядке; команды — как в турнирной таблице.
     *
     * @param  list<array<string, mixed>>  $played
     * @param  list<array<string, mixed>>  $standings
     * @return list<array<string, mixed>>
     */
    private static function teamForm(array $played, array $standings): array
    {
        $byTeam = [];
        foreach ($played as $m) {
            foreach (['home' => 'away', 'away' => 'home'] as $side => $other) {
                $gf = $m[$side.'_goals'];
                $ga = $m[$other.'_goals'];
                $byTeam[$m[$side]][] = [
                    'round' => $m['round'],
                    'result' => self::resultOf($gf, $ga),
                    'venue' => $side,
                    'opponent' => $m[$other],
                    'score' => $gf.':'.$ga,
                    'date' => $m['date'],
                ];
            }
        }

        return array_map(static fn (array $row) => [
            'pos' => $row['pos'],
            'team' => $row['team'],
            'matches' => $byTeam[$row['team']] ?? [],
        ], $standings);
    }

    /**
     * Сыгранные туры: от последнего к первому, матчи в хронологическом порядке внутри тура.
     *
     * @param  list<array<string, mixed>>  $played
     * @return list<array<string, mixed>>
     */
    private static function results(array $played): array
    {
        $rounds = [];
        foreach ($played as $m) {
            $rounds[$m['round']][] = [
                'home' => $m['home'],
                'away' => $m['away'],
                'home_goals' => $m['home_goals'],
                'away_goals' => $m['away_goals'],
                'date' => $m['date'],
                'time' => $m['time'],
            ];
        }
        krsort($rounds);

        return self::roundBlocks($rounds);
    }

    /**
     * Предстоящие туры по порядку. Только у ближайшего тура — вероятности П1 / Х / П2 и топ-рынок,
     * как в списке матчей на главной; считаются по голам команд (модель Пуассона, преимущество поля).
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  list<array<string, mixed>>  $standings
     * @return list<array<string, mixed>>
     */
    private static function schedule(array $matches, array $standings): array
    {
        $next = self::ROUNDS_PLAYED + 1;
        $rates = self::goalRates($standings);

        $rounds = [];
        foreach ($matches as $m) {
            if ($m['home_goals'] !== null) {
                continue;
            }
            $row = [
                'home' => $m['home'],
                'away' => $m['away'],
                'date' => $m['date'],
                'time' => $m['time'],
            ];
            if ($m['round'] === $next) {
                $row += self::forecast($rates[$m['home']], $rates[$m['away']], $rates['_avg']);
            }
            $rounds[$m['round']][] = $row;
        }

        $blocks = self::roundBlocks($rounds);
        foreach ($blocks as &$block) {
            $block['is_next'] = $block['round'] === $next;
        }
        unset($block);

        return $blocks;
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $rounds  тур → матчи
     * @return list<array<string, mixed>>
     */
    private static function roundBlocks(array $rounds): array
    {
        $blocks = [];
        foreach ($rounds as $round => $matches) {
            usort($matches, static fn (array $a, array $b) => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);
            $blocks[] = [
                'round' => $round,
                'date_from' => $matches[0]['date'],
                'date_to' => $matches[array_key_last($matches)]['date'],
                'matches' => $matches,
            ];
        }

        return $blocks;
    }

    /**
     * Забито / пропущено за матч у каждой команды и среднее число голов команды за матч по лиге.
     *
     * @param  list<array<string, mixed>>  $standings
     * @return array<string, mixed>
     */
    private static function goalRates(array $standings): array
    {
        $rates = [];
        $goals = 0;
        $games = 0;
        foreach ($standings as $row) {
            $rates[$row['team']] = ['gf' => $row['gf'] / $row['played'], 'ga' => $row['ga'] / $row['played']];
            $goals += $row['gf'];
            $games += $row['played'];
        }
        $avg = $goals / $games;
        foreach ($rates as &$rate) {
            $rate = [
                'gf' => $avg + self::RATE_WEIGHT * ($rate['gf'] - $avg),
                'ga' => $avg + self::RATE_WEIGHT * ($rate['ga'] - $avg),
            ];
        }
        unset($rate);
        $rates['_avg'] = $avg;

        return $rates;
    }

    /**
     * Вероятности 1X2 и рынка из ожидаемых голов: сила атаки × слабость защиты соперника × средний уровень лиги.
     *
     * @param  array{gf: float, ga: float}  $home
     * @param  array{gf: float, ga: float}  $away
     * @return array{prob_home: int, prob_draw: int, prob_away: int, top_market: array{label: string, prob: int}}
     */
    private static function forecast(array $home, array $away, float $avg): array
    {
        $homeAdvantage = 1.12;
        $lambdaHome = $avg * ($home['gf'] / $avg) * ($away['ga'] / $avg) * $homeAdvantage;
        $lambdaAway = $avg * ($away['gf'] / $avg) * ($home['ga'] / $avg) / $homeAdvantage;

        $pmf = static function (float $lambda): array {
            $p = [exp(-$lambda)];
            for ($k = 1; $k <= 10; $k++) {
                $p[$k] = $p[$k - 1] * $lambda / $k;
            }

            return $p;
        };
        $ph = $pmf($lambdaHome);
        $pa = $pmf($lambdaAway);

        $win = $draw = $loss = $over = $both = $total = 0.0;
        foreach ($ph as $h => $x) {
            foreach ($pa as $a => $y) {
                $p = $x * $y;
                $total += $p;
                if ($h > $a) {
                    $win += $p;
                } elseif ($h < $a) {
                    $loss += $p;
                } else {
                    $draw += $p;
                }
                if ($h + $a >= 3) {
                    $over += $p;
                }
                if ($h > 0 && $a > 0) {
                    $both += $p;
                }
            }
        }

        [$p1, $px, $p2] = self::percentages([$win / $total, $draw / $total, $loss / $total]);

        $markets = [
            ['label' => 'ТБ 2.5', 'prob' => (int) round($over / $total * 100)],
            ['label' => 'ТМ 2.5', 'prob' => 100 - (int) round($over / $total * 100)],
            ['label' => 'Обе забьют', 'prob' => (int) round($both / $total * 100)],
        ];
        usort($markets, static fn (array $a, array $b) => $b['prob'] <=> $a['prob']);

        return ['prob_home' => $p1, 'prob_draw' => $px, 'prob_away' => $p2, 'top_market' => $markets[0]];
    }

    /**
     * Доли → целые проценты ровно на 100 (метод наибольших остатков).
     *
     * @param  list<float>  $shares
     * @return list<int>
     */
    private static function percentages(array $shares): array
    {
        $floors = array_map(static fn (float $s) => (int) floor($s * 100), $shares);
        $order = array_keys($shares);
        usort($order, static fn (int $a, int $b) => ($shares[$b] * 100 - $floors[$b]) <=> ($shares[$a] * 100 - $floors[$a]));
        for ($i = 0, $left = 100 - array_sum($floors); $i < $left; $i++) {
            $floors[$order[$i]]++;
        }

        return $floors;
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
     * Лучшие / худшие атаки и защиты из турнирной таблицы: голы и голы на матч (игры из таблицы).
     * Равные значения идут в порядке турнирной таблицы.
     *
     * @param  list<array<string, mixed>>  $standings
     * @return array<string, list<array<string, mixed>>>
     */
    private static function attackDefense(array $standings): array
    {
        $build = static function (string $field, bool $descending) use ($standings): array {
            $teams = $standings;
            usort($teams, static fn (array $a, array $b) => $descending
                ? $b[$field] <=> $a[$field]
                : $a[$field] <=> $b[$field]);

            $rows = [];
            foreach (array_slice($teams, 0, self::TEAM_TOP_LIMIT) as $i => $t) {
                $rows[] = [
                    'rank' => $i + 1,
                    'team' => $t['team'],
                    'goals' => $t[$field],
                    'per_match' => round($t[$field] / $t['played'], 2),
                ];
            }

            return $rows;
        };

        return [
            'best_attack' => $build('gf', true),
            'best_defense' => $build('ga', false),
            'worst_attack' => $build('gf', false),
            'worst_defense' => $build('ga', true),
        ];
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
     * @param  list<array<string, mixed>>  $standings
     * @return array<string, list<array<string, mixed>>>
     */
    private static function contributionByContext(array $players, array $standings): array
    {
        $teamGoals = array_column($standings, 'gf', 'team');

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
