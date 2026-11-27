<?php

namespace App\Services\Picks;

use App\Enums\MatchStatus;
use App\Models\FootballMatch;

/** Выбор открыт только до старта: матч не начат и kickoff в будущем. */
final class PickWindow
{
    public static function isOpen(FootballMatch $match): bool
    {
        return $match->status === MatchStatus::Scheduled && $match->kickoff_at->isFuture();
    }
}
