<?php

namespace App\Enums;

enum ExternalEntityType: string
{
    case Country = 'country';
    case Competition = 'competition';
    case Season = 'season';
    case Stage = 'stage';
    case Round = 'round';
    case Tie = 'tie';
    case Team = 'team';
    case Venue = 'venue';
    case Player = 'player';
    case Coach = 'coach';
    case Referee = 'referee';
    case Match = 'match';
    case MarketType = 'market_type';
}
