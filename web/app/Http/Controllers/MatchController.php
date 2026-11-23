<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MatchController extends Controller
{
    /**
     * Список матчей лиги (главная).
     *
     * Демо-данные: достаточно для оценки UI до появления collector/модели.
     */
    public function index(Request $request): Response
    {
        $leagues = $this->demoLeagues();
        $liveIds = collect($leagues)->where('status', 'live')->pluck('id');

        $requestedLeague = $request->query('league');
        $defaultLive = $liveIds->first() ?? 'rpl';
        // «all» — матчи всех live-лиг; иначе — конкретная live-лига.
        if ($requestedLeague === 'all' || $requestedLeague === null || $requestedLeague === '') {
            $selectedLeague = 'all';
        } elseif ($liveIds->contains($requestedLeague)) {
            $selectedLeague = $requestedLeague;
        } else {
            $selectedLeague = 'all';
        }

        $period = $request->query('period', 'upcoming');
        if (! in_array($period, ['today', 'upcoming', 'finished'], true)) {
            $period = 'upcoming';
        }

        // Виджеты таблицы/бомбардиров — по конкретной лиге (для «Все» — первая live).
        $widgetLeague = $selectedLeague === 'all' ? $defaultLive : $selectedLeague;
        if (! $liveIds->contains($widgetLeague)) {
            $widgetLeague = $defaultLive;
        }

        return Inertia::render('Matches/Index', [
            'leagues' => $leagues,
            'matches' => $this->demoMatches(),
            'selectedLeague' => $selectedLeague,
            'period' => $period,
            'widgets' => $this->demoWidgets($widgetLeague),
        ]);
    }

    /**
     * Страница матча: события и вероятности (заглушка).
     */
    public function show(int $match): Response
    {
        $demo = collect($this->demoMatches())->firstWhere('id', $match);
        $leagues = collect($this->demoLeagues())->keyBy('id');

        if ($demo) {
            $league = $leagues->get($demo['league_id']);
            $demo['league'] = $league
                ? $league['country'].' · '.$league['name']
                : 'РПЛ';
        }

        return Inertia::render('Matches/Show', [
            'match' => $demo ?? [
                'id' => $match,
                'home' => 'Команда А',
                'away' => 'Команда Б',
                'kickoff_at' => null,
                'status' => 'scheduled',
                'league' => 'РПЛ',
                'league_id' => 'rpl',
            ],
            'events' => [],
            'preview' => null,
        ]);
    }

    /**
     * @return list<array{id: string, country: string, name: string, short: string, status: string}>
     */
    private function demoLeagues(): array
    {
        return [
            ['id' => 'rpl', 'country' => 'Россия', 'name' => 'РПЛ', 'short' => 'РПЛ', 'status' => 'live'],
            ['id' => 'epl', 'country' => 'Англия', 'name' => 'Premier League', 'short' => 'EPL', 'status' => 'soon'],
            ['id' => 'laliga', 'country' => 'Испания', 'name' => 'La Liga', 'short' => 'LL', 'status' => 'soon'],
            ['id' => 'seriea', 'country' => 'Италия', 'name' => 'Serie A', 'short' => 'SA', 'status' => 'soon'],
            ['id' => 'bundesliga', 'country' => 'Германия', 'name' => 'Bundesliga', 'short' => 'BL', 'status' => 'soon'],
            ['id' => 'ligue1', 'country' => 'Франция', 'name' => 'Ligue 1', 'short' => 'L1', 'status' => 'soon'],
            ['id' => 'ucl', 'country' => 'Европа', 'name' => 'Champions League', 'short' => 'UCL', 'status' => 'soon'],
            ['id' => 'eredivisie', 'country' => 'Нидерланды', 'name' => 'Eredivisie', 'short' => 'ERE', 'status' => 'soon'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function demoMatches(): array
    {
        $today = now()->startOfDay();

        return [
            [
                'id' => 101,
                'league_id' => 'rpl',
                'home' => 'Зенит',
                'away' => 'Спартак',
                'kickoff_at' => $today->copy()->setTime(19, 30)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'today',
                'prob_home' => 48,
                'prob_draw' => 26,
                'prob_away' => 26,
            ],
            [
                'id' => 102,
                'league_id' => 'rpl',
                'home' => 'ЦСКА',
                'away' => 'Динамо',
                'kickoff_at' => $today->copy()->setTime(16, 0)->toIso8601String(),
                'status' => 'live',
                'score_home' => 1,
                'score_away' => 1,
                'period_bucket' => 'today',
                'prob_home' => 35,
                'prob_draw' => 31,
                'prob_away' => 34,
            ],
            [
                'id' => 103,
                'league_id' => 'rpl',
                'home' => 'Краснодар',
                'away' => 'Локомотив',
                'kickoff_at' => $today->copy()->addDay()->setTime(18, 0)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'upcoming',
                'prob_home' => 52,
                'prob_draw' => 24,
                'prob_away' => 24,
            ],
            [
                'id' => 104,
                'league_id' => 'rpl',
                'home' => 'Ростов',
                'away' => 'Рубин',
                'kickoff_at' => $today->copy()->addDays(2)->setTime(14, 30)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'upcoming',
                'prob_home' => 41,
                'prob_draw' => 28,
                'prob_away' => 31,
            ],
            [
                'id' => 105,
                'league_id' => 'rpl',
                'home' => 'Сочи',
                'away' => 'Ахмат',
                'kickoff_at' => $today->copy()->addDays(3)->setTime(17, 15)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'upcoming',
                'prob_home' => 38,
                'prob_draw' => 30,
                'prob_away' => 32,
            ],
            [
                'id' => 106,
                'league_id' => 'rpl',
                'home' => 'Пари НН',
                'away' => 'Факел',
                'kickoff_at' => $today->copy()->subDay()->setTime(19, 0)->toIso8601String(),
                'status' => 'finished',
                'score_home' => 2,
                'score_away' => 0,
                'period_bucket' => 'finished',
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
            ],
            [
                'id' => 107,
                'league_id' => 'rpl',
                'home' => 'Оренбург',
                'away' => 'Химки',
                'kickoff_at' => $today->copy()->subDays(2)->setTime(15, 30)->toIso8601String(),
                'status' => 'finished',
                'score_home' => 1,
                'score_away' => 3,
                'period_bucket' => 'finished',
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
            ],
        ];
    }

    /**
     * Демо-виджеты правой колонки (таблица, бомбардиры, скорострелы, новости).
     *
     * @return array<string, mixed>
     */
    private function demoWidgets(string $leagueId): array
    {
        $league = collect($this->demoLeagues())->firstWhere('id', $leagueId)
            ?? ['id' => 'rpl', 'country' => 'Россия', 'name' => 'РПЛ', 'short' => 'РПЛ'];

        return [
            'league' => [
                'id' => $league['id'],
                'label' => $league['country'].' · '.$league['name'],
                'short' => $league['short'] ?? $league['name'],
            ],
            'standings' => [
                ['pos' => 1, 'team' => 'Зенит', 'played' => 14, 'gd' => 18, 'pts' => 34],
                ['pos' => 2, 'team' => 'Краснодар', 'played' => 14, 'gd' => 14, 'pts' => 31],
                ['pos' => 3, 'team' => 'Спартак', 'played' => 14, 'gd' => 10, 'pts' => 28],
                ['pos' => 4, 'team' => 'ЦСКА', 'played' => 14, 'gd' => 8, 'pts' => 26],
                ['pos' => 5, 'team' => 'Динамо', 'played' => 14, 'gd' => 5, 'pts' => 24],
                ['pos' => 6, 'team' => 'Локомотив', 'played' => 14, 'gd' => 3, 'pts' => 22],
                ['pos' => 7, 'team' => 'Ростов', 'played' => 14, 'gd' => 1, 'pts' => 20],
                ['pos' => 8, 'team' => 'Рубин', 'played' => 14, 'gd' => -2, 'pts' => 18],
            ],
            'scorers' => [
                ['pos' => 1, 'player' => 'М. Глушенков', 'team' => 'Зенит', 'goals' => 11],
                ['pos' => 2, 'player' => 'Э. Сперанцон', 'team' => 'Краснодар', 'goals' => 9],
                ['pos' => 3, 'player' => 'Т. Барко', 'team' => 'Спартак', 'goals' => 8],
                ['pos' => 4, 'player' => 'И. Обляков', 'team' => 'ЦСКА', 'goals' => 7],
                ['pos' => 5, 'player' => 'К. Тюкавин', 'team' => 'Динамо', 'goals' => 7],
            ],
            'hotScorers' => [
                ['pos' => 1, 'player' => 'Э. Холанд', 'team' => 'Man City', 'goals' => 28, 'league' => 'EPL'],
                ['pos' => 2, 'player' => 'К. Мбаппе', 'team' => 'Real Madrid', 'goals' => 24, 'league' => 'LL'],
                ['pos' => 3, 'player' => 'Г. Кане', 'team' => 'Bayern', 'goals' => 22, 'league' => 'BL'],
                ['pos' => 4, 'player' => 'Л. Диас', 'team' => 'Liverpool', 'goals' => 19, 'league' => 'EPL'],
                ['pos' => 5, 'player' => 'М. Глушенков', 'team' => 'Зенит', 'goals' => 17, 'league' => 'РПЛ'],
            ],
            'news' => [
                [
                    'id' => 1,
                    'title' => 'Зенит — Спартак: ключевые события 0–100',
                    'tag' => 'Превью',
                    'ago' => '2 ч',
                ],
                [
                    'id' => 2,
                    'title' => 'Как читать вероятности MatchIQ перед матчем',
                    'tag' => 'Гид',
                    'ago' => '5 ч',
                ],
                [
                    'id' => 3,
                    'title' => 'Рейтинг: одно событие — один матч',
                    'tag' => 'Рейтинг',
                    'ago' => '1 д',
                ],
                [
                    'id' => 4,
                    'title' => 'РПЛ тур: где модель сильнее рынка',
                    'tag' => 'Аналитика',
                    'ago' => '2 д',
                ],
            ],
        ];
    }
}
