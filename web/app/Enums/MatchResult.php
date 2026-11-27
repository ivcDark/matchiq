<?php

namespace App\Enums;

/** Исход матча с точки зрения команды; значения — буквы формы в UI (В / Н / П). */
enum MatchResult: string
{
    case Win = 'В';
    case Draw = 'Н';
    case Loss = 'П';

    public static function fromGoals(int $for, int $against): self
    {
        return match (true) {
            $for > $against => self::Win,
            $for < $against => self::Loss,
            default => self::Draw,
        };
    }

    public function points(): int
    {
        return match ($this) {
            self::Win => 3,
            self::Draw => 1,
            self::Loss => 0,
        };
    }
}
