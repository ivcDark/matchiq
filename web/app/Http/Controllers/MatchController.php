<?php

namespace App\Http\Controllers;

use App\Http\Requests\MatchIndexRequest;
use App\Http\Responses\Matches\MatchesIndexResponse;
use App\Http\Responses\Matches\MatchShowResponse;
use App\Services\Matches\BuildMatchesIndex;
use App\Services\Matches\BuildMatchShow;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(MatchIndexRequest $request, BuildMatchesIndex $index): MatchesIndexResponse
    {
        return $index->handle($request->leagueInput(), $request->dateInput(), $request->periodInput());
    }

    public function show(Request $request, BuildMatchShow $show, int $match): MatchShowResponse
    {
        $returnLeague = $request->query('league');

        return $show->handle($match, is_string($returnLeague) && $returnLeague !== '' ? $returnLeague : null, $request->user())
            ?? abort(404);
    }
}
