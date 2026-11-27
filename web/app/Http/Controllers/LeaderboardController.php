<?php

namespace App\Http\Controllers;

use App\Services\Leaderboard\BuildLeaderboard;
use App\Support\MockLeaderboard;
use App\Support\RatingSeason;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    /** Таблица рейтинга: очки за разрешённые picks (угадал — 100 × (1 − p), промах — 0). */
    public function index(Request $request, BuildLeaderboard $leaderboard): Response
    {
        $period = MockLeaderboard::resolvePeriod($request->query('period'));
        $season = RatingSeason::forDate();

        return Inertia::render('Leaderboard/Index', [
            'period' => $period,
            'periods' => MockLeaderboard::periods($season),
            'season' => $season->toArray(),
        ] + $leaderboard->handle($period, $season, $request->user()));
    }
}
