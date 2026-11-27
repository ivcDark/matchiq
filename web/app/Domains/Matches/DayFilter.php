<?php

namespace App\Domains\Matches;

use App\Enums\MatchDayPeriod;
use Carbon\CarbonImmutable;

/**
 * Фильтр дня на главной: `?period=yesterday|today|tomorrow` или `?date=YYYY-MM-DD`.
 * `date` приоритетнее; если день совпадает с вчера/сегодня/завтра — период именованный.
 */
final readonly class DayFilter
{
    public function __construct(
        public ?MatchDayPeriod $period,
        public CarbonImmutable $date,
    ) {}

    public static function resolve(?string $dateInput, ?string $periodInput, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        $picked = self::parseDate($dateInput);
        if ($picked !== null) {
            foreach (MatchDayPeriod::cases() as $period) {
                if ($picked->equalTo($today->addDays($period->offsetDays()))) {
                    return new self($period, $picked);
                }
            }

            return new self(null, $picked);
        }

        $period = MatchDayPeriod::fromInput($periodInput);

        return new self($period, $today->addDays($period->offsetDays()));
    }

    private static function parseDate(?string $input): ?CarbonImmutable
    {
        if ($input === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $input);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $input ? $date : null;
    }

    /** Окно ±1 день вокруг дня: клиент отрезает свои локальные сутки, сервер не знает часовой пояс браузера. */
    public function windowStart(): CarbonImmutable
    {
        return $this->date->subDay();
    }

    public function windowEnd(): CarbonImmutable
    {
        return $this->date->addDays(2);
    }
}
