<?php

namespace App\Domains\Tournaments;

use App\Enums\MatchStatus;
use App\Models\FootballMatch;

final class FinishedMatch
{
    public static function is(FootballMatch $match): bool
    {
        return $match->status === MatchStatus::Finished
            && $match->home_goals !== null
            && $match->away_goals !== null;
    }
}
