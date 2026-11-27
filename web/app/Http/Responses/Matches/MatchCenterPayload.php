<?php

namespace App\Http\Responses\Matches;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StatsPeriod;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchTeamStat;
use Illuminate\Support\Collection;

final class MatchCenterPayload
{
    /**
     * [поле, подпись, формат, лучшее значение, раздел] — только метрики, которые есть в `match_team_stats`.
     */
    private const METRICS = [
        ['xg', 'Ожидаемые голы (xG)', 'dec', 'high', 'xg'],
        ['xga', 'Допущенные xG (xGA)', 'dec', 'low', 'xg'],
        ['shots', 'Удары', 'int', 'high', 'attack'],
        ['shots_on_target', 'Удары в створ', 'int', 'high', 'attack'],
        ['corners', 'Угловые', 'int', 'high', 'attack'],
        ['offsides', 'Офсайды', 'int', 'low', 'attack'],
        ['possession_pct', 'Владение мячом', 'pct', 'high', 'control'],
        ['passes', 'Передачи', 'int', 'high', 'control'],
        ['pass_accuracy_pct', 'Точность передач', 'pct', 'high', 'control'],
        ['aerials_won', 'Выигранные верховые', 'int', 'high', 'control'],
        ['fouls', 'Фолы', 'int', 'low', 'discipline'],
        ['yellow_cards', 'Жёлтые карточки', 'int', 'low', 'discipline'],
        ['red_cards', 'Красные карточки', 'int', 'low', 'discipline'],
        ['saves', 'Сейвы', 'int', 'high', 'defense'],
    ];

    private const SECTIONS = [
        'xg' => ['Ожидаемые голы', 'left'],
        'attack' => ['Атака', 'right'],
        'control' => ['Игра с мячом', 'left'],
        'discipline' => ['Дисциплина', 'right'],
        'defense' => ['Защита', 'right'],
    ];

    /**
     * Сводка live/finished: хроника и статистика из БД. Составы и лучшие игроки пока не отдаются (null),
     * null и сам блок, если детали матча ещё не загружены.
     *
     * @return array<string, mixed>|null
     */
    public static function make(FootballMatch $match): ?array
    {
        if (! in_array($match->status, [MatchStatus::Live, MatchStatus::Finished], true)) {
            return null;
        }

        $events = self::events($match);
        $stats = self::stats($match);

        if ($events === [] && $stats === null) {
            return null;
        }

        return [
            'minute' => $match->status === MatchStatus::Live ? $match->minute : null,
            'events' => $events,
            'stats' => $stats,
            'best_players' => null,
            'lineups' => null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function events(FootballMatch $match): array
    {
        $locale = config('matchiq.ui_locale');
        $rows = [];

        foreach ($match->events as $event) {
            $type = self::eventType($event);
            if ($type === null || $event->team_id === null) {
                continue;
            }

            $row = [
                'minute' => $event->minute,
                'side' => $event->team_id === $match->home_team_id ? 'home' : 'away',
                'type' => $type,
                'player' => $event->player?->localized('name', $locale),
                'assist' => null,
                'player_in' => null,
                'player_out' => null,
                'score_home' => $event->score_home,
                'score_away' => $event->score_away,
            ];

            if ($type === 'sub') {
                $row['player_out'] = $row['player'];
                $row['player_in'] = $event->relatedPlayer?->localized('name', $locale);
            } elseif ($type === 'goal' && $event->type !== MatchEventType::OwnGoal) {
                $row['assist'] = $event->relatedPlayer?->localized('name', $locale);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function eventType(MatchEvent $event): ?string
    {
        return match ($event->type) {
            MatchEventType::Goal, MatchEventType::PenaltyGoal, MatchEventType::OwnGoal => 'goal',
            MatchEventType::Yellow => 'yellow',
            MatchEventType::SecondYellow, MatchEventType::Red => 'red',
            MatchEventType::Substitution => 'sub',
            default => null,
        };
    }

    /** @return array{match: ?list<array<string, mixed>>, first_half: ?list<array<string, mixed>>, second_half: ?list<array<string, mixed>>}|null */
    private static function stats(FootballMatch $match): ?array
    {
        $byPeriod = $match->teamStats->groupBy(fn (MatchTeamStat $s) => $s->period->value);

        $periods = [
            'match' => self::sections($byPeriod->get(StatsPeriod::FullTime->value), $match),
            'first_half' => self::sections($byPeriod->get(StatsPeriod::FirstHalf->value), $match),
            'second_half' => self::sections($byPeriod->get(StatsPeriod::SecondHalf->value), $match),
        ];

        return $periods['match'] === null ? null : $periods;
    }

    /**
     * @param  Collection<int, MatchTeamStat>|null  $stats
     * @return list<array<string, mixed>>|null
     */
    private static function sections(?Collection $stats, FootballMatch $match): ?array
    {
        $home = $stats?->firstWhere('team_id', $match->home_team_id);
        $away = $stats?->firstWhere('team_id', $match->away_team_id);
        if ($home === null || $away === null) {
            return null;
        }

        $grouped = [];
        foreach (self::METRICS as [$field, $label, $fmt, $better, $section]) {
            $h = self::value($home->{$field}, $fmt);
            $a = self::value($away->{$field}, $fmt);
            if ($h === null && $a === null) {
                continue;
            }
            $grouped[$section][] = [
                'id' => $field, 'label' => $label, 'home' => $h, 'away' => $a,
                'fmt' => $fmt, 'better' => $better, 'hint' => null,
            ];
        }

        $sections = [];
        foreach (self::SECTIONS as $id => [$title, $column]) {
            if (isset($grouped[$id])) {
                $sections[] = ['id' => $id, 'title' => $title, 'column' => $column, 'rows' => $grouped[$id]];
            }
        }

        return $sections === [] ? null : $sections;
    }

    private static function value(mixed $raw, string $fmt): int|float|null
    {
        if ($raw === null) {
            return null;
        }

        return $fmt === 'int' ? (int) $raw : (float) $raw;
    }
}
