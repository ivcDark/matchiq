<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Единый сезон рейтинга платформы: с 1 августа по 31 июля.
 * Не зависит от сезонов конкретных чемпионатов.
 */
class RatingSeason
{
    public const START_MONTH = 8;

    public const HINT = 'Сезон рейтинга действует с 1 августа по 31 июля и не зависит от сезонов чемпионатов';

    private const MONTHS_SHORT = [
        1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн',
        7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
    ];

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * Сезон, в который попадает дата (по умолчанию — сегодня).
     */
    public static function forDate(?CarbonInterface $date = null): self
    {
        $date = CarbonImmutable::instance($date ?? now())->startOfDay();

        $startYear = $date->month >= self::START_MONTH ? $date->year : $date->year - 1;

        return new self(
            CarbonImmutable::create($startYear, self::START_MONTH, 1)->startOfDay(),
            CarbonImmutable::create($startYear + 1, self::START_MONTH - 1, 31)->endOfDay(),
        );
    }

    /** «2026/27» */
    public function label(): string
    {
        return $this->start->year.'/'.substr((string) $this->end->year, -2);
    }

    /** «Сезон 2026/27» */
    public function title(): string
    {
        return 'Сезон '.$this->label();
    }

    /** «1 авг 2026 — 31 июл 2027» */
    public function range(): string
    {
        return $this->formatDay($this->start).' — '.$this->formatDay($this->end);
    }

    public function contains(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->start, $this->end);
    }

    /**
     * @return array{label: string, title: string, range: string, start: string, end: string, hint: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'title' => $this->title(),
            'range' => $this->range(),
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'hint' => self::HINT,
        ];
    }

    private function formatDay(CarbonInterface $date): string
    {
        return $date->day.' '.self::MONTHS_SHORT[$date->month].' '.$date->year;
    }
}
