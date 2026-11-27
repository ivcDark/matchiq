<?php

namespace App\Services\Picks;

use App\Enums\PickStatus;
use App\Models\FootballMatch;
use App\Models\Pick;
use App\Models\PredictionOutcome;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сохраняет выбор пользователя («Поставить» в корзине). Правило MVP: один активный pick на матч —
 * новый заменяет прежний (тот закрывается как void). Вероятность фиксируется снимком на момент выбора.
 */
class PlacePick
{
    public function handle(User $user, FootballMatch $match, int $predictionOutcomeId): Pick
    {
        if (! PickWindow::isOpen($match)) {
            throw PickException::closed();
        }

        $outcome = PredictionOutcome::query()
            ->whereKey($predictionOutcomeId)
            ->whereHas('matchPrediction', fn ($q) => $q->where('match_id', $match->id)->where('is_current', true))
            ->first();

        if ($outcome === null) {
            throw PickException::unknownOutcome();
        }

        return DB::transaction(function () use ($user, $match, $outcome) {
            $active = Pick::query()->active()->where('user_id', $user->id)->where('match_id', $match->id)->lockForUpdate()->first();

            if ($active?->prediction_outcome_id === $outcome->id) {
                return $active;
            }

            $active?->update(['superseded_at' => now(), 'status' => PickStatus::Void, 'points' => 0]);

            return Pick::create([
                'user_id' => $user->id,
                'match_id' => $match->id,
                'prediction_outcome_id' => $outcome->id,
                'market_type_id' => $outcome->market_type_id,
                'outcome_key' => $outcome->outcome_key,
                'line' => $outcome->line,
                'probability_at_pick' => $outcome->probability,
                'status' => PickStatus::Pending,
                'points' => 0,
                'picked_at' => now(),
            ]);
        });
    }
}
