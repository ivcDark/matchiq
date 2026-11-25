<?php

namespace App\Enums;

enum CompetitionScope: string
{
    case Domestic = 'domestic';
    case Continental = 'continental';
    case World = 'world';
}
