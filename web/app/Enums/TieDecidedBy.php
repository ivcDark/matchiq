<?php

namespace App\Enums;

enum TieDecidedBy: string
{
    case SingleMatch = 'single_match';
    case Aggregate = 'aggregate';
    case AwayGoals = 'away_goals';
    case ExtraTime = 'extra_time';
    case Penalties = 'penalties';
    case Walkover = 'walkover';
}
