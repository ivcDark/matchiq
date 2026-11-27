<?php

namespace App\Http\Responses\Matches;

use App\Models\Competition;

final class LeaguePayload
{
    /**
     * `id` — slug лиги: именно им фронт фильтрует матчи и строит `?league=`.
     * `tournament_id` пока null: страница турнира работает на демо-данных и не привязана к реальной лиге.
     *
     * @return array{id: string, country: string, country_code: ?string, name: string, short: string, status: string, sort_order: int, tournament_id: null}
     */
    public static function make(Competition $competition): array
    {
        $locale = config('matchiq.ui_locale');
        $name = $competition->localized('name', $locale);

        return [
            'id' => $competition->slug,
            'country' => $competition->country?->localized('name', $locale) ?? '',
            'country_code' => $competition->country?->code,
            'name' => $name,
            'short' => $competition->localized('short_name', $locale) ?: $name,
            'status' => $competition->status->value,
            'sort_order' => $competition->sort_order,
            'tournament_id' => null,
        ];
    }

    public static function label(Competition $competition): string
    {
        $league = self::make($competition);

        return trim($league['country'].' · '.$league['name'], ' ·');
    }
}
