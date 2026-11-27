<?php

namespace App\Http\Controllers;

use App\Http\Responses\Matches\MatchMarketsPayload;
use App\Models\Pick;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeController extends Controller
{
    private const PICKS_LIMIT = 50;

    /** Профиль и мои выборы: матч, событие, вероятность на момент выбора, статус и очки. */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $locale = config('matchiq.ui_locale');

        $picks = Pick::query()
            ->with(['match.homeTeam', 'match.awayTeam', 'marketType.outcomes'])
            ->active()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'void')
            ->latest('picked_at')
            ->limit(self::PICKS_LIMIT)
            ->get();

        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'points' => (int) Pick::query()->active()->where('user_id', $user->id)->sum('points'),
            ],
            'picks' => $picks->map(function (Pick $pick) use ($locale) {
                $view = MatchMarketsPayload::userPick($pick, null, $locale);

                return [
                    'match_id' => $pick->match_id,
                    'match' => $pick->match->homeTeam->localized('name', $locale).' — '.$pick->match->awayTeam->localized('name', $locale),
                    'kickoff_at' => $pick->match->kickoff_at->toIso8601String(),
                    'event' => $view['label'],
                    'market' => $view['market'],
                    'probability' => (int) round((float) $pick->probability_at_pick * 100),
                    'status' => $pick->status->value,
                    'points' => $pick->points,
                ];
            })->values()->all(),
        ]);
    }
}
