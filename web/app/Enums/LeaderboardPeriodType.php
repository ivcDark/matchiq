<?php

namespace App\Enums;

enum LeaderboardPeriodType: string
{
    case Week = 'week';
    case Month = 'month';
    case Season = 'season';
    case AllTime = 'all_time';
}
