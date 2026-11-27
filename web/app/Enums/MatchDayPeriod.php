<?php

namespace App\Enums;

/** Именованный день-фильтр главной. */
enum MatchDayPeriod: string
{
    case Yesterday = 'yesterday';
    case Today = 'today';
    case Tomorrow = 'tomorrow';

    public function offsetDays(): int
    {
        return match ($this) {
            self::Yesterday => -1,
            self::Today => 0,
            self::Tomorrow => 1,
        };
    }

    /** Старые значения MVP → ближайший аналог. */
    public static function fromInput(?string $value): self
    {
        return match ($value) {
            'upcoming' => self::Tomorrow,
            'finished' => self::Yesterday,
            default => self::tryFrom((string) $value) ?? self::Today,
        };
    }
}
