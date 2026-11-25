<?php

namespace App\Enums;

enum IngestionKind: string
{
    case Schedule = 'schedule';
    case MatchStats = 'match_stats';
    case Lineups = 'lineups';
    case Live = 'live';
}
