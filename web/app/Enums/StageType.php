<?php

namespace App\Enums;

enum StageType: string
{
    case League = 'league';
    case Group = 'group';
    case Knockout = 'knockout';
    case Playoff = 'playoff';
    /** Вне MVP, задел в модели. */
    case Friendly = 'friendly';

    public function hasStandings(): bool
    {
        return $this === self::League || $this === self::Group;
    }

    public function usesTies(): bool
    {
        return $this === self::Knockout || $this === self::Playoff;
    }
}
