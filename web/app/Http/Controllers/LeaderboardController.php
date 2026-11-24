<?php

namespace App\Http\Controllers;

use App\Support\MockLeaderboard;
use App\Support\RatingSeason;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    /**
     * Таблица рейтинга (моки без реального начисления очков).
     */
    public function index(Request $request): Response
    {
        $period = MockLeaderboard::resolvePeriod($request->query('period'));
        $season = RatingSeason::forDate();
        $user = $request->user();
        $entries = MockLeaderboard::entries(
            $period,
            $user ? ['id' => $user->id, 'name' => $user->name] : null,
        );

        return Inertia::render('Leaderboard/Index', [
            'period' => $period,
            'periods' => MockLeaderboard::periods($season),
            'season' => $season->toArray(),
            'entries' => $entries,
            'totalPlayers' => MockLeaderboard::TOTAL_PLAYERS,
            'currentUserRank' => collect($entries)->firstWhere('is_me', true)['rank'] ?? null,
        ]);
    }
}
