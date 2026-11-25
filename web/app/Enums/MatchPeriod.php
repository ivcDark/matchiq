<?php

namespace App\Enums;

enum MatchPeriod: string
{
    case FirstHalf = 'first_half';
    case HalfTime = 'half_time';
    case SecondHalf = 'second_half';
    case ExtraTime = 'extra_time';
    case Penalties = 'penalties';
}
