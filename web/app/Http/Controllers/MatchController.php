<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
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

        $selectedLeagues = $this->resolveSelectedLeagues($request->query('league'), $liveIds);
        $defaultLive = $liveIds->first() ?? 'rpl';

        [$period, $date] = $this->resolveDateFilter(
            $request->query('date'),
            $request->query('period'),
        );

        // Виджеты — по первой выбранной live-лиге (для «Все» — первая live).
        $widgetLeague = $selectedLeagues === ['all']
            ? $defaultLive
            : ($selectedLeagues[0] ?? $defaultLive);
        if (! $liveIds->contains($widgetLeague)) {
            $widgetLeague = $defaultLive;
        }

        return Inertia::render('Matches/Index', [
            'leagues' => $leagues,
            'matches' => $this->demoMatches(),
            'selectedLeagues' => $selectedLeagues,
            'period' => $period,
            'date' => $date,
            'widgets' => $this->demoWidgets($widgetLeague),
        ]);
    }

    /**
     * Фильтр дня: `?period=yesterday|today|tomorrow` или `?date=YYYY-MM-DD`.
     * `date` приоритетнее; если день совпадает с вчера/сегодня/завтра — period именованный.
     *
     * @return array{0: ?string, 1: string} [period, date Y-m-d]
     */
    private function resolveDateFilter(mixed $dateRaw, mixed $periodRaw): array
    {
        $today = now()->startOfDay();

        if (is_string($dateRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
            try {
                $picked = Carbon::createFromFormat('Y-m-d', $dateRaw)->startOfDay();

                return $this->namedPeriodForDate($picked, $today);
            } catch (\Throwable) {
                // fall through to period / default
            }
        }

        $period = is_string($periodRaw) ? $periodRaw : null;

        // Старые значения MVP → ближайший аналог.
        if ($period === 'upcoming') {
            $period = 'tomorrow';
        } elseif ($period === 'finished') {
            $period = 'yesterday';
        }

        if (! in_array($period, ['yesterday', 'today', 'tomorrow'], true)) {
            $period = 'today';
        }

        $date = match ($period) {
            'yesterday' => $today->copy()->subDay(),
            'tomorrow' => $today->copy()->addDay(),
            default => $today->copy(),
        };

        return [$period, $date->toDateString()];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function namedPeriodForDate(Carbon $picked, Carbon $today): array
    {
        $date = $picked->toDateString();

        if ($picked->equalTo($today)) {
            return ['today', $date];
        }
        if ($picked->equalTo($today->copy()->subDay())) {
            return ['yesterday', $date];
        }
        if ($picked->equalTo($today->copy()->addDay())) {
            return ['tomorrow', $date];
        }

        return [null, $date];
    }

    /**
     * Мультивыбор лиг из query: `league=all` | `league=rpl,ucl` | `league[]=rpl&league[]=ucl`.
     * «all» эксклюзивно; неизвестные / soon-id отбрасываются; пустой набор → all.
     *
     * @param  mixed  $raw
     * @param  \Illuminate\Support\Collection<int, string>  $liveIds
     * @return list<string>
     */
    private function resolveSelectedLeagues(mixed $raw, $liveIds): array
    {
        if ($raw === null || $raw === '') {
            return ['all'];
        }

        if (is_array($raw)) {
            $tokens = $raw;
        } else {
            $tokens = preg_split('/\s*,\s*/', (string) $raw) ?: [];
        }

        $tokens = array_values(array_filter(array_map(
            static fn ($t) => is_string($t) ? trim($t) : '',
            $tokens,
        )));

        if ($tokens === [] || $tokens === ['all']) {
            return ['all'];
        }

        // Нельзя смешивать «Все» с конкретными — при смеси берём только конкретные live.
        $tokens = array_values(array_filter($tokens, static fn (string $t) => $t !== 'all'));

        $selected = [];
        foreach ($tokens as $id) {
            if ($liveIds->contains($id) && ! in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        }

        return $selected === [] ? ['all'] : $selected;
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
     * @return list<array{id: string, country: string, name: string, short: string, status: string, sort_order: int}>
     */
    private function demoLeagues(): array
    {
        // sort_order — демо-поле порядка групп на главной (позже из админки).
        return [
            ['id' => 'rpl', 'country' => 'Россия', 'name' => 'РПЛ', 'short' => 'РПЛ', 'status' => 'live', 'sort_order' => 10],
            ['id' => 'fnl', 'country' => 'Россия', 'name' => 'ФНЛ', 'short' => 'ФНЛ', 'status' => 'live', 'sort_order' => 20],
            ['id' => 'epl', 'country' => 'Англия', 'name' => 'Premier League', 'short' => 'EPL', 'status' => 'soon', 'sort_order' => 40],
            ['id' => 'laliga', 'country' => 'Испания', 'name' => 'La Liga', 'short' => 'LL', 'status' => 'soon', 'sort_order' => 50],
            ['id' => 'seriea', 'country' => 'Италия', 'name' => 'Serie A', 'short' => 'SA', 'status' => 'soon', 'sort_order' => 60],
            ['id' => 'bundesliga', 'country' => 'Германия', 'name' => 'Bundesliga', 'short' => 'BL', 'status' => 'soon', 'sort_order' => 70],
            ['id' => 'ligue1', 'country' => 'Франция', 'name' => 'Ligue 1', 'short' => 'L1', 'status' => 'soon', 'sort_order' => 80],
            ['id' => 'ucl', 'country' => 'Европа', 'name' => 'Champions League', 'short' => 'UCL', 'status' => 'live', 'sort_order' => 30],
            ['id' => 'eredivisie', 'country' => 'Нидерланды', 'name' => 'Eredivisie', 'short' => 'ERE', 'status' => 'soon', 'sort_order' => 90],
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
                'form_home' => ['W', 'W', 'D', 'W', 'L'],
                'form_away' => ['L', 'W', 'W', 'D', 'W'],
                'prob_home' => 42,
                'prob_draw' => 2,
                'prob_away' => 56,
                'top_market' => ['label' => 'ТМ 3.5', 'prob' => 82],
            ],
            [
                'id' => 108,
                'league_id' => 'rpl',
                'home' => 'Крылья Советов',
                'away' => 'Акрон',
                'kickoff_at' => $today->copy()->setTime(17, 0)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'today',
                'form_home' => ['D', 'L', 'W', 'W', 'D'],
                'form_away' => ['W', 'D', 'L', 'L', 'W'],
                'prob_home' => 48,
                'prob_draw' => 26,
                'prob_away' => 26,
                'top_market' => ['label' => 'ТБ 2.5', 'prob' => 71],
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
                'form_home' => ['W', 'D', 'W', 'L', 'W'],
                'form_away' => ['D', 'W', 'L', 'W', 'D'],
                'prob_home' => 35,
                'prob_draw' => 31,
                'prob_away' => 34,
                'top_market' => ['label' => 'Обе забьют', 'prob' => 68],
            ],
            [
                'id' => 109,
                'league_id' => 'rpl',
                'home' => 'Торпедо',
                'away' => 'Динамо Мх',
                'kickoff_at' => $today->copy()->setTime(14, 0)->toIso8601String(),
                'status' => 'finished',
                'score_home' => 0,
                'score_away' => 2,
                'period_bucket' => 'today',
                'form_home' => ['L', 'L', 'D', 'W', 'L'],
                'form_away' => ['W', 'W', 'W', 'D', 'L'],
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
                'top_market' => null,
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
                'form_home' => ['W', 'W', 'W', 'D', 'W'],
                'form_away' => ['L', 'D', 'W', 'L', 'D'],
                'prob_home' => 52,
                'prob_draw' => 24,
                'prob_away' => 24,
                'top_market' => ['label' => 'ТМ 2.5', 'prob' => 61],
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
                'form_home' => ['D', 'W', 'L', 'W', 'W'],
                'form_away' => ['W', 'L', 'D', 'W', 'L'],
                'prob_home' => 41,
                'prob_draw' => 28,
                'prob_away' => 31,
                'top_market' => ['label' => 'ТБ 1.5', 'prob' => 77],
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
                'form_home' => ['L', 'W', 'D', 'D', 'W'],
                'form_away' => ['D', 'D', 'L', 'W', 'W'],
                'prob_home' => 38,
                'prob_draw' => 30,
                'prob_away' => 32,
                'top_market' => ['label' => 'ТМ 3.5', 'prob' => 74],
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
                'form_home' => ['W', 'W', 'L', 'W', 'D'],
                'form_away' => ['L', 'L', 'W', 'D', 'L'],
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
                'top_market' => null,
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
                'form_home' => ['D', 'L', 'L', 'W', 'D'],
                'form_away' => ['W', 'W', 'D', 'W', 'W'],
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
                'top_market' => null,
            ],
            [
                'id' => 151,
                'league_id' => 'fnl',
                'home' => 'Балтика',
                'away' => 'Урал',
                'kickoff_at' => $today->copy()->setTime(18, 0)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'today',
                'form_home' => ['W', 'D', 'W', 'W', 'L'],
                'form_away' => ['L', 'W', 'D', 'L', 'W'],
                'prob_home' => 46,
                'prob_draw' => 27,
                'prob_away' => 27,
                'top_market' => ['label' => 'ТМ 2.5', 'prob' => 59],
            ],
            [
                'id' => 152,
                'league_id' => 'fnl',
                'home' => 'Черноморец',
                'away' => 'Ротор',
                'kickoff_at' => $today->copy()->addDay()->setTime(16, 30)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'upcoming',
                'form_home' => ['L', 'D', 'W', 'D', 'W'],
                'form_away' => ['W', 'L', 'W', 'D', 'D'],
                'prob_home' => 43,
                'prob_draw' => 28,
                'prob_away' => 29,
                'top_market' => ['label' => 'ТБ 2.5', 'prob' => 66],
            ],
            [
                'id' => 201,
                'league_id' => 'ucl',
                'home' => 'Real Madrid',
                'away' => 'Man City',
                'kickoff_at' => $today->copy()->setTime(22, 0)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'today',
                'form_home' => ['W', 'W', 'D', 'W', 'W'],
                'form_away' => ['W', 'L', 'W', 'D', 'W'],
                'prob_home' => 44,
                'prob_draw' => 25,
                'prob_away' => 31,
                'top_market' => ['label' => 'ТБ 3.5', 'prob' => 58],
            ],
            [
                'id' => 202,
                'league_id' => 'ucl',
                'home' => 'Bayern',
                'away' => 'PSG',
                'kickoff_at' => $today->copy()->addDay()->setTime(22, 0)->toIso8601String(),
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'period_bucket' => 'upcoming',
                'form_home' => ['W', 'D', 'W', 'L', 'W'],
                'form_away' => ['D', 'W', 'W', 'W', 'L'],
                'prob_home' => 49,
                'prob_draw' => 24,
                'prob_away' => 27,
                'top_market' => ['label' => 'ТМ 3.5', 'prob' => 79],
            ],
            [
                'id' => 203,
                'league_id' => 'ucl',
                'home' => 'Inter',
                'away' => 'Arsenal',
                'kickoff_at' => $today->copy()->subDay()->setTime(22, 0)->toIso8601String(),
                'status' => 'finished',
                'score_home' => 2,
                'score_away' => 1,
                'period_bucket' => 'finished',
                'form_home' => ['W', 'W', 'L', 'D', 'W'],
                'form_away' => ['L', 'W', 'D', 'L', 'W'],
                'prob_home' => null,
                'prob_draw' => null,
                'prob_away' => null,
                'top_market' => null,
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
