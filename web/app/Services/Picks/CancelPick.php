<?php

namespace App\Services\Picks;

use App\Enums\PickStatus;
use App\Models\FootballMatch;
use App\Models\Pick;
use App\Models\User;

/** Снимает активный pick до старта матча. */
class CancelPick
{
    public function handle(User $user, FootballMatch $match): void
    {
        if (! PickWindow::isOpen($match)) {
            throw PickException::closed();
        }

        Pick::query()->active()->where('user_id', $user->id)->where('match_id', $match->id)->update([
            'superseded_at' => now(),
            'status' => PickStatus::Void,
            'points' => 0,
        ]);
    }
}
