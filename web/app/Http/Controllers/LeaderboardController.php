<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    /**
     * Таблица рейтинга (заглушка).
     */
    public function index(): Response
    {
        return Inertia::render('Leaderboard/Index', [
            'entries' => [],
            'period' => 'all_time',
        ]);
    }
}
