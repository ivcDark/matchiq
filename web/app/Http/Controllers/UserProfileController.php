<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MockLeaderboard;
use App\Support\MockUserProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    /**
     * Публичный профиль игрока (моки без реальной статистики).
     */
    public function show(Request $request, string $nick): Response
    {
        $registeredAt = null;
        $isMe = false;

        if (! MockLeaderboard::isKnownNick($nick)) {
            $user = User::where('name', $nick)->first();
            abort_if($user === null, 404);

            $registeredAt = $user->created_at;
            $isMe = $request->user()?->is($user) ?? false;
        }

        return Inertia::render('Users/Show', MockUserProfile::build($nick, $registeredAt, $isMe));
    }
}
