<?php

namespace App\Http\Controllers;

use App\Support\MockTournament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TournamentController extends Controller
{
    /**
     * Страница турнира: таблица и топ-списки игроков (моки без реальной статистики).
     * Сезон — `?season=2025-26`; неизвестный ключ открывает текущий сезон.
     */
    public function show(Request $request, int $id): Response
    {
        $season = $request->query('season');
        $data = MockTournament::find($id, null, is_string($season) ? $season : null);

        abort_if($data === null, 404);

        return Inertia::render('Tournaments/Show', $data);
    }
}
