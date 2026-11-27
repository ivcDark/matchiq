<?php

namespace App\Domains\Predictions;

/**
 * Каталог рынков MVP: единый источник для сидера `market_types`, эвристической модели и резолва исходов.
 * Рынок с линией (`lines`) в прогнозе разворачивается в одну карточку на линию.
 */
final class MarketCatalog
{
    public const RESULT = 'result_1x2';

    public const DOUBLE_CHANCE = 'double_chance';

    public const BTTS = 'btts';

    public const TOTAL_GOALS = 'total_goals';

    public const TOTAL_CORNERS = 'total_corners';

    public const TOTAL_YELLOWS = 'total_yellows';

    public const RED_CARD = 'red_card';

    /**
     * @return list<array{
     *     code: string, category: string, name: string, name_ru: string, has_line: bool, is_popular: bool,
     *     sort_order: int, lines: list<float>, outcomes: list<array{0: string, 1: string, 2: string}>
     * }>
     */
    public static function definitions(): array
    {
        $overUnder = [['over', 'Over', 'ТБ'], ['under', 'Under', 'ТМ']];
        $yesNo = [['yes', 'Yes', 'Да'], ['no', 'No', 'Нет']];

        return [
            [
                'code' => self::RESULT, 'category' => 'results', 'name' => 'Match result', 'name_ru' => 'Исход матча',
                'has_line' => false, 'is_popular' => false, 'sort_order' => 10, 'lines' => [],
                'outcomes' => [['home', 'Home win', 'П1'], ['draw', 'Draw', 'Ничья'], ['away', 'Away win', 'П2']],
            ],
            [
                'code' => self::DOUBLE_CHANCE, 'category' => 'results', 'name' => 'Double chance', 'name_ru' => 'Двойной шанс',
                'has_line' => false, 'is_popular' => false, 'sort_order' => 20, 'lines' => [],
                'outcomes' => [['1x', 'Home or draw', '1X'], ['12', 'Home or away', '12'], ['x2', 'Draw or away', 'X2']],
            ],
            [
                'code' => self::TOTAL_GOALS, 'category' => 'totals', 'name' => 'Total goals', 'name_ru' => 'Тотал голов',
                'has_line' => true, 'is_popular' => true, 'sort_order' => 30, 'lines' => [1.5, 2.5, 3.5],
                'outcomes' => $overUnder,
            ],
            [
                'code' => self::BTTS, 'category' => 'goals', 'name' => 'Both teams to score', 'name_ru' => 'Обе забьют',
                'has_line' => false, 'is_popular' => true, 'sort_order' => 40, 'lines' => [],
                'outcomes' => $yesNo,
            ],
            [
                'code' => self::TOTAL_CORNERS, 'category' => 'corners', 'name' => 'Total corners', 'name_ru' => 'Угловые, тотал',
                'has_line' => true, 'is_popular' => true, 'sort_order' => 50, 'lines' => [8.5, 9.5, 10.5],
                'outcomes' => $overUnder,
            ],
            [
                'code' => self::TOTAL_YELLOWS, 'category' => 'yellows', 'name' => 'Total yellow cards', 'name_ru' => 'Жёлтые карточки, тотал',
                'has_line' => true, 'is_popular' => true, 'sort_order' => 60, 'lines' => [3.5, 4.5, 5.5],
                'outcomes' => $overUnder,
            ],
            [
                'code' => self::RED_CARD, 'category' => 'reds', 'name' => 'Red card in match', 'name_ru' => 'Красная карточка',
                'has_line' => false, 'is_popular' => false, 'sort_order' => 70, 'lines' => [],
                'outcomes' => $yesNo,
            ],
        ];
    }
}
