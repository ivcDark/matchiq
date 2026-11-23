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
        $selectedLeague = $liveIds->contains($requestedLeague)
            ? $requestedLeague
            : $defaultLive;

        $period = $request->query('period', 'upcoming');
        if (! in_array($period, ['today', 'upcoming', 'finished'], true)) {
            $period = 'upcoming';
        }

        return Inertia::render('Matches/Index', [
            'leagues' => $leagues,
            'matches' => $this->demoMatches(),
            'selectedLeague' => $selectedLeague,
            'period' => $period,
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
     * @return list<array{id: string, country: string, name: string, status: string}>
     */
    private function demoLeagues(): array
    {
        return [
            ['id' => 'rpl', 'country' => 'Россия', 'name' => 'РПЛ', 'status' => 'live'],
            ['id' => 'epl', 'country' => 'Англия', 'name' => 'Premier League', 'status' => 'soon'],
            ['id' => 'laliga', 'country' => 'Испания', 'name' => 'La Liga', 'status' => 'soon'],
            ['id' => 'seriea', 'country' => 'Италия', 'name' => 'Serie A', 'status' => 'soon'],
            ['id' => 'bundesliga', 'country' => 'Германия', 'name' => 'Bundesliga', 'status' => 'soon'],
            ['id' => 'ligue1', 'country' => 'Франция', 'name' => 'Ligue 1', 'status' => 'soon'],
            ['id' => 'ucl', 'country' => 'Европа', 'name' => 'Champions League', 'status' => 'soon'],
            ['id' => 'eredivisie', 'country' => 'Нидерланды', 'name' => 'Eredivisie', 'status' => 'soon'],
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
            ],
        ];
    }
}
