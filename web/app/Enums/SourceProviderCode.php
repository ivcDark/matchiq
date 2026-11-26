<?php

namespace App\Enums;

/** `source_providers.code`. */
enum SourceProviderCode: string
{
    case Sstats = 'sstats';

    public function label(): string
    {
        return match ($this) {
            self::Sstats => 'SStats.net',
        };
    }
}
