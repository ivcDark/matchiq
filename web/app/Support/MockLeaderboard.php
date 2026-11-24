<?php

namespace App\Support;

/**
 * Моковые данные рейтинга: общий источник для /leaderboard и профилей игроков.
 */
class MockLeaderboard
{
    public const PERIODS = [
        ['id' => 'week', 'label' => 'Неделя'],
        ['id' => 'month', 'label' => 'Месяц'],
        ['id' => 'season', 'label' => 'Сезон'],
        ['id' => 'all_time', 'label' => 'Всё время'],
    ];

    public const DEFAULT_PERIOD = 'season';

    public const TOTAL_PLAYERS = 1284;

    /** Доля «всех прогнозов» в выбранном периоде. */
    private const PERIOD_SCALE = [
        'week' => 0.04,
        'month' => 0.16,
        'season' => 0.62,
        'all_time' => 1.0,
    ];

    /** Позиция в моках, на которую подставляется текущий пользователь. */
    private const ME_SLOT = 6;

    /**
     * Ник, прогнозов, угадано, очки, серия — за всё время (моки).
     *
     * @var list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private const PLAYERS = [
        ['Бомбардир_77', 412, 231, 4860, 9],
        ['ТактикСПб', 388, 214, 4520, 6],
        ['Футболоман', 351, 190, 3980, 4],
        ['Сергеич', 340, 181, 3710, 7],
        ['ГолевойПас', 296, 158, 3290, 3],
        ['xG_Мастер', 274, 143, 3050, 5],
        ['Тренер_Игорь', 251, 128, 2740, 2],
        ['Лига_Чемпионов', 233, 117, 2510, 1],
        ['Оффсайд', 208, 100, 2190, 3],
        ['Дамир_К', 187, 88, 1930, 0],
        ['РПЛ_Фанат', 162, 74, 1640, 2],
        ['Штрафной', 141, 61, 1380, 1],
    ];

    public static function resolvePeriod(mixed $value): string
    {
        $ids = array_column(self::PERIODS, 'id');

        return is_string($value) && in_array($value, $ids, true) ? $value : self::DEFAULT_PERIOD;
    }

    public static function isKnownNick(string $nick): bool
    {
        return in_array($nick, array_column(self::PLAYERS, 0), true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(string $period, ?string $meName = null): array
    {
        $scale = self::PERIOD_SCALE[$period];
        $periodIndex = array_search($period, array_column(self::PERIODS, 'id'), true);

        $rows = [];
        foreach (self::PLAYERS as $i => [$nick, $picks, $correct, $points, $streak]) {
            // Детерминированный разброс, чтобы порядок отличался между периодами.
            $jitter = 1 + ((($i * 7 + $periodIndex * 3) % 5) - 2) * 0.06;
            $scaledPicks = max(3, (int) round($picks * $scale));
            $scaledCorrect = min($scaledPicks, max(1, (int) round($correct * $scale * $jitter)));

            $isMe = $meName !== null && $i === self::ME_SLOT;

            $rows[] = [
                'name' => $isMe ? $meName : $nick,
                'picks' => $scaledPicks,
                'correct' => $scaledCorrect,
                'accuracy' => (int) round($scaledCorrect / $scaledPicks * 100),
                'points' => (int) round($points * $scale * $jitter),
                'streak' => $period === 'week' ? min($streak, 4) : $streak,
                'is_me' => $isMe,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['points'] <=> $a['points']);

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }
}
