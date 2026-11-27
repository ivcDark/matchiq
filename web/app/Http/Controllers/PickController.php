<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePickRequest;
use App\Models\FootballMatch;
use App\Services\Picks\CancelPick;
use App\Services\Picks\PickException;
use App\Services\Picks\PlacePick;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PickController extends Controller
{
    /** «Поставить» в корзине: сохраняет выбор (заменяя прежний на этот матч). */
    public function store(StorePickRequest $request, PlacePick $place, int $match): RedirectResponse
    {
        $found = FootballMatch::findOrFail($match);

        try {
            $place->handle($request->user(), $found, $request->outcomeId());
        } catch (PickException $e) {
            throw ValidationException::withMessages(['pick' => $e->getMessage()]);
        }

        return back()->with('status', 'Выбор сохранён');
    }

    public function destroy(Request $request, CancelPick $cancel, int $match): RedirectResponse
    {
        try {
            $cancel->handle($request->user(), FootballMatch::findOrFail($match));
        } catch (PickException $e) {
            throw ValidationException::withMessages(['pick' => $e->getMessage()]);
        }

        return back()->with('status', 'Выбор снят');
    }
}
