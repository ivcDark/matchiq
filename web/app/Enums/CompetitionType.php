<?php

namespace App\Enums;

enum CompetitionType: string
{
    case League = 'league';
    case Cup = 'cup';
    case Tournament = 'tournament';
    /** Вне MVP, задел в модели. */
    case Friendly = 'friendly';
}
