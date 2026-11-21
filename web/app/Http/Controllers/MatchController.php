<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class MatchController extends Controller
{
    /**
     * Список матчей лиги (главная).
     */
    public function index(): Response
    {
        return Inertia::render('Matches/Index', [
            'league' => 'rpl',
            'period' => 'upcoming',
            'matches' => [],
        ]);
    }

    /**
     * Страница матча: события и вероятности (заглушка).
     */
    public function show(int $match): Response
    {
        return Inertia::render('Matches/Show', [
            'match' => [
                'id' => $match,
                'home' => 'Команда А',
                'away' => 'Команда Б',
                'kickoff_at' => null,
                'status' => 'scheduled',
                'league' => 'РПЛ',
            ],
            'events' => [],
            'preview' => null,
        ]);
    }
}
