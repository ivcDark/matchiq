<?php

namespace App\Http\Controllers;

use App\Support\MockLeaderboard;
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
        $entries = MockLeaderboard::entries($period, $request->user()?->name);

        return Inertia::render('Leaderboard/Index', [
            'period' => $period,
            'periods' => MockLeaderboard::PERIODS,
            'entries' => $entries,
            'totalPlayers' => MockLeaderboard::TOTAL_PLAYERS,
            'currentUserRank' => collect($entries)->firstWhere('is_me', true)['rank'] ?? null,
        ]);
    }
}
