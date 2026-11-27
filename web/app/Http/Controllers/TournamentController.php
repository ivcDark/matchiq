<?php

namespace App\Http\Controllers;

use App\Http\Responses\Tournaments\TournamentShowResponse;
use App\Services\Tournaments\BuildTournamentShow;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    /** Сезон — `?season=<slug сезона>`; неизвестный ключ открывает текущий сезон. */
    public function show(Request $request, BuildTournamentShow $show, int $id): TournamentShowResponse
    {
        $season = $request->query('season');

        return $show->handle($id, is_string($season) && $season !== '' ? $season : null) ?? abort(404);
    }
}
