<?php

namespace App\Http\Controllers;

use App\Support\MockTournament;
use Inertia\Inertia;
use Inertia\Response;

class TournamentController extends Controller
{
    /**
     * Страница турнира: таблица и топ-списки игроков (моки без реальной статистики).
     */
    public function show(int $id): Response
    {
        $data = MockTournament::find($id);

        abort_if($data === null, 404);

        return Inertia::render('Tournaments/Show', $data);
    }
}
