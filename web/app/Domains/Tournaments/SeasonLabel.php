<?php

namespace App\Domains\Tournaments;

final class SeasonLabel
{
    /** «2026-2027» → «2026/27»; нестандартные подписи остаются как есть. */
    public static function format(string $label): string
    {
        if (preg_match('/^(\d{4})\s*[-\/]\s*(\d{4})$/', $label, $m) === 1) {
            return $m[1].'/'.substr($m[2], 2);
        }

        return $label;
    }
}
