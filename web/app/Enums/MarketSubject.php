<?php

namespace App\Enums;

enum MarketSubject: string
{
    case Match = 'match';
    case HomeTeam = 'home_team';
    case AwayTeam = 'away_team';
}
