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
     * Публичный профиль игрока по числовому id (моки без реальной статистики).
     */
    public function show(Request $request, int $id): Response
    {
        if (MockLeaderboard::isMockId($id)) {
            $nick = MockLeaderboard::nickById($id);
            $registeredAt = null;
            $isMe = false;
        } else {
            $user = User::find($id);
            abort_if($user === null, 404);

            $nick = $user->name;
            $registeredAt = $user->created_at;
            $isMe = $request->user()?->is($user) ?? false;
        }

        return Inertia::render('Users/Show', MockUserProfile::build($id, $nick, $registeredAt, $isMe));
    }
}
