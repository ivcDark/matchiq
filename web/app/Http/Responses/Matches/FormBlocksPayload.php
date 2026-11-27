<?php

namespace App\Http\Responses\Matches;

use App\Domains\Matches\TeamForm;
use App\Models\FootballMatch;

final class FormBlocksPayload
{
    private const LIMIT = 5;

    /**
     * Пять карточек: личные встречи и последние матчи каждой команды (все, дома/в гостях) до начала этого матча.
     *
     * @return list<array<string, mixed>>
     */
    public static function make(FootballMatch $match, TeamForm $form): array
    {
        $locale = config('matchiq.ui_locale');
        $before = $match->kickoff_at;
        $homeId = $match->home_team_id;
        $awayId = $match->away_team_id;
        $homeName = $match->homeTeam->localized('name', $locale);
        $awayName = $match->awayTeam->localized('name', $locale);

        $blocks = [
            ['h2h', 'Последние 5 между этими командами', $homeId, $form->headToHead($homeId, $awayId, $before, self::LIMIT)],
            ['home_all', 'Последние 5 · '.$homeName, $homeId, $form->recent($homeId, $before, self::LIMIT)],
            ['away_all', 'Последние 5 · '.$awayName, $awayId, $form->recent($awayId, $before, self::LIMIT)],
            ['home_home', 'Последние 5 · '.$homeName.' дома', $homeId, $form->recent(
                $homeId, $before, self::LIMIT, fn (FootballMatch $m) => $m->home_team_id === $homeId,
            )],
            ['away_away', 'Последние 5 · '.$awayName.' в гостях', $awayId, $form->recent(
                $awayId, $before, self::LIMIT, fn (FootballMatch $m) => $m->away_team_id === $awayId,
            )],
        ];

        return array_map(fn (array $b) => [
            'id' => $b[0],
            'title' => $b[1],
            'focus' => $b[2] === $homeId ? $homeName : $awayName,
            'matches' => array_map(fn (FootballMatch $m) => self::row($m, $b[2], $form, $locale), array_reverse($b[3])),
        ], $blocks);
    }

    /** @return array<string, mixed> */
    private static function row(FootballMatch $m, int $focusTeamId, TeamForm $form, string $locale): array
    {
        $competition = $m->competition;

        return [
            'date' => $m->kickoff_at->toDateString(),
            'home' => $m->homeTeam->localized('name', $locale),
            'away' => $m->awayTeam->localized('name', $locale),
            'score_home' => $m->home_goals,
            'score_away' => $m->away_goals,
            'ht_home' => $m->home_goals_ht,
            'ht_away' => $m->away_goals_ht,
            'competition' => $competition->localized('short_name', $locale) ?: $competition->localized('name', $locale),
            'result' => $form->resultFor($m, $focusTeamId)->value,
        ];
    }
}
