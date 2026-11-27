<?php

namespace App\Http\Responses\Matches;

use App\Models\MarketType;
use App\Models\MatchPrediction;
use App\Models\Pick;
use App\Models\PredictionOutcome;
use App\Repositories\PickRepository;
use App\Support\RatingRules;

final class MatchMarketsPayload
{
    /**
     * Карточки событий текущего прогноза (рынок × линия) с вероятностью, потенциальными очками и долей выбравших.
     *
     * @param  array<string, int>  $popularity  PickRepository::popularity()
     * @return list<array<string, mixed>>
     */
    public static function make(?MatchPrediction $prediction, array $popularity, string $locale): array
    {
        if ($prediction === null) {
            return [];
        }

        $totalPicks = array_sum($popularity);
        $cards = [];

        foreach ($prediction->outcomes as $outcome) {
            $type = $outcome->marketType;
            $line = $outcome->line === null ? null : (float) $outcome->line;
            $cardId = $type->code.':'.($line === null ? '-' : self::line($line));

            $cards[$cardId] ??= [
                'sort' => [$type->sort_order, $line ?? 0.0],
                'data' => [
                    'id' => $cardId,
                    'code' => $type->code,
                    'category' => $type->category->value,
                    'name' => self::cardName($type, $line, $locale),
                    'popular_pct' => null,
                    'outcomes' => [],
                ],
            ];

            $count = $popularity[PickRepository::key($type->id, $line, $outcome->outcome_key)] ?? 0;
            $pct = (int) round(((float) $outcome->probability) * 100);

            $cards[$cardId]['data']['outcomes'][] = [
                'id' => $outcome->id,
                'key' => $outcome->outcome_key,
                'label' => self::outcomeLabel($type, $outcome->outcome_key, $line, $locale),
                'prob' => $pct,
                'points' => RatingRules::points($pct),
                'popular_pct' => $totalPicks > 0 ? (int) round($count / $totalPicks * 100) : null,
                'hit' => $outcome->is_hit,
                '_order' => $type->outcomes->firstWhere('outcome_key', $outcome->outcome_key)?->sort_order ?? 99,
            ];
        }

        uasort($cards, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_values(array_map(function (array $card) {
            $outcomes = $card['data']['outcomes'];
            usort($outcomes, fn (array $a, array $b) => $a['_order'] <=> $b['_order']);
            $popular = array_filter(array_column($outcomes, 'popular_pct'), fn ($v) => $v !== null);
            $card['data']['popular_pct'] = $popular === [] ? null : max($popular);
            $card['data']['outcomes'] = array_map(function (array $o) {
                unset($o['_order']);

                return $o;
            }, $outcomes);

            return $card['data'];
        }, $cards));
    }

    /** Выбор пользователя для UI: его событие в терминах текущего прогноза (id для подсветки в карточках). */
    public static function userPick(?Pick $pick, ?MatchPrediction $current, string $locale): ?array
    {
        if ($pick === null) {
            return null;
        }

        $type = $pick->marketType;
        $line = $pick->line === null ? null : (float) $pick->line;
        $pct = (int) round(((float) $pick->probability_at_pick) * 100);

        $currentOutcome = $current?->outcomes->first(fn (PredictionOutcome $o) => $o->market_type_id === $pick->market_type_id
            && $o->outcome_key === $pick->outcome_key
            && ($o->line === null ? null : (float) $o->line) === $line);

        return [
            'id' => $pick->id,
            'outcome_id' => $currentOutcome?->id ?? $pick->prediction_outcome_id,
            'market' => self::cardName($type, $line, $locale),
            'label' => self::outcomeLabel($type, $pick->outcome_key, $line, $locale),
            'probability' => $pct,
            'potential_points' => RatingRules::points($pct),
            'status' => $pick->status->value,
            'points' => $pick->points,
        ];
    }

    private static function cardName(MarketType $type, ?float $line, string $locale): string
    {
        $name = (string) $type->localized('name', $locale);

        return $line === null ? $name : $name.' '.self::line($line);
    }

    private static function outcomeLabel(MarketType $type, string $key, ?float $line, string $locale): string
    {
        $label = $type->outcomes->firstWhere('outcome_key', $key)?->localized('label', $locale) ?? $key;

        return $line === null ? $label : $label.' '.self::line($line);
    }

    private static function line(float $line): string
    {
        return rtrim(rtrim(number_format($line, 2, '.', ''), '0'), '.');
    }
}
