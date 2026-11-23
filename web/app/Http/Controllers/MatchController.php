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
     * Страница матча: вероятности, стата сравнения, форма, комментарии (демо).
     */
    public function show(Request $request, int $match): Response
    {
        $demo = collect($this->demoMatches())->firstWhere('id', $match);
        $leagues = collect($this->demoLeagues())->keyBy('id');

        if (! $demo) {
            $demo = [
                'id' => $match,
                'home' => 'Команда А',
                'away' => 'Команда Б',
                'kickoff_at' => null,
                'status' => 'scheduled',
                'score_home' => null,
                'score_away' => null,
                'league_id' => 'rpl',
                'form_home' => ['В', 'Н', 'П', 'В', 'Н'],
                'form_away' => ['П', 'В', 'В', 'Н', 'П'],
                'prob_home' => 40,
                'prob_draw' => 28,
                'prob_away' => 32,
                'top_market' => null,
            ];
        }

        $league = $leagues->get($demo['league_id']) ?? [
            'id' => 'rpl',
            'country' => 'Россия',
            'name' => 'РПЛ',
            'short' => 'РПЛ',
            'status' => 'live',
        ];

        $detail = $this->demoMatchDetail($demo, $league);
        $returnLeague = $request->query('league');

        return Inertia::render('Matches/Show', [
            'match' => $detail['match'],
            'preview' => $detail['preview'],
            'markets' => $detail['markets'],
            'comparison' => $detail['comparison'],
            'homeAway' => $detail['homeAway'],
            'standings' => $detail['standings'],
            'scorers' => $detail['scorers'],
            'absences' => $detail['absences'],
            'goalMinutes' => $detail['goalMinutes'],
            'formBlocks' => $detail['formBlocks'],
            'forecast' => $detail['forecast'],
            'insights' => $detail['insights'],
            'trends' => $detail['trends'],
            'goalProfile' => $detail['goalProfile'],
            'refereeStats' => $detail['refereeStats'],
            'comments' => $detail['comments'],
            'userPickEventId' => null,
            'returnLeague' => is_string($returnLeague) && $returnLeague !== '' ? $returnLeague : null,
        ]);
    }

    /**
     * Богатый демо-пакет для UI страницы матча (до появления модели/collector).
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $league
     * @return array<string, mixed>
     */
    private function demoMatchDetail(array $match, array $league): array
    {
        $home = $match['home'];
        $away = $match['away'];
        $id = (int) $match['id'];
        $status = $match['status'] ?? 'scheduled';
        $isFinished = $status === 'finished';
        $isLive = $status === 'live';

        $meta = $this->demoMatchMeta($id, $league['id'] ?? 'rpl');

        $enriched = array_merge($match, [
            'league' => ($league['country'] ?? '').' · '.($league['name'] ?? ''),
            'league_short' => $league['short'] ?? $league['name'] ?? 'Лига',
            'league_country' => $league['country'] ?? '',
            'venue' => $meta['venue'],
            'referee' => $meta['referee'],
            'round' => $meta['round'],
            'weather' => $this->demoWeather($id, $league['id'] ?? 'rpl'),
        ]);

        $markets = $this->demoMarketsForMatch($id, $status, $match);

        return [
            'match' => $enriched,
            'preview' => $this->demoPreview($home, $away, $status),
            'insights' => $this->demoInsights($home, $away, $id),
            'forecast' => $this->demoForecast($home, $away, $id, $markets),
            'markets' => $markets,
            'trends' => $this->demoTrends($home, $away, $id),
            'goalProfile' => $this->demoGoalProfile($home, $away, $id),
            'comparison' => [
                'match' => $this->demoComparison($home, $away, $id, 'match'),
                'first_half' => $this->demoComparison($home, $away, $id, 'first_half'),
                'second_half' => $this->demoComparison($home, $away, $id, 'second_half'),
            ],
            'homeAway' => $this->demoHomeAwaySplits($home, $away, $id),
            'standings' => $this->demoMiniStandings($home, $away, $league['id'] ?? 'rpl'),
            'scorers' => [
                'home' => $this->demoTeamScorers($home, $id, 'home'),
                'away' => $this->demoTeamScorers($away, $id, 'away'),
            ],
            'absences' => [
                'home' => $this->demoAbsences($home, $id, 'home'),
                'away' => $this->demoAbsences($away, $id, 'away'),
            ],
            'goalMinutes' => $this->demoGoalMinutes($id),
            'formBlocks' => $this->demoFormBlocks(
                $home,
                $away,
                $id,
                $league['short'] ?? $league['name'] ?? 'РПЛ',
            ),
            'refereeStats' => $this->demoRefereeStats($meta['referee'], $id),
            'comments' => $this->demoComments($home, $away, $id, $isFinished || $isLive),
        ];
    }

    /**
     * @return array{venue: string, referee: string, round: string}
     */
    private function demoMatchMeta(int $id, string $leagueId): array
    {
        $venues = [
            101 => ['venue' => 'Газпром Арена, Санкт-Петербург', 'referee' => 'В. Мешков', 'round' => '15 тур'],
            102 => ['venue' => 'ВЭБ Арена, Москва', 'referee' => 'С. Карасев', 'round' => '15 тур'],
            108 => ['venue' => 'Солидарность Самара Арена', 'referee' => 'А. Матюнин', 'round' => '15 тур'],
            201 => ['venue' => 'Santiago Bernabéu, Madrid', 'referee' => 'M. Oliver', 'round' => 'MD 5'],
        ];

        if (isset($venues[$id])) {
            return $venues[$id];
        }

        $fallbackVenue = match ($leagueId) {
            'ucl' => 'Neutral / stadium TBD',
            'fnl' => 'Стадион лиги, Россия',
            default => 'Стадион, Россия',
        };

        return [
            'venue' => $fallbackVenue,
            'referee' => 'Назначение позже',
            'round' => $leagueId === 'ucl' ? 'Group stage' : 'Тур РПЛ',
        ];
    }

    /**
     * @return array{temp_c: int, wind_ms: int, condition: string}
     */
    private function demoWeather(int $id, string $leagueId): array
    {
        $conditions = ['облачно', 'ясно', 'пасмурно', 'морось', 'ветрено'];

        return [
            'temp_c' => $leagueId === 'ucl' ? 14 + ($id % 8) : 1 + ($id % 11),
            'wind_ms' => 2 + ($id % 7),
            'condition' => $conditions[$id % count($conditions)],
        ];
    }

    /**
     * Статистика судьи за текущий сезон: Все / РПЛ / Кубок России.
     * При малом числе матчей в выборке averages = null → UI показывает «—».
     *
     * @return array{
     *     name: string,
     *     place: string,
     *     season: string,
     *     tabs: list<array{id: string, label: string}>,
     *     samples: array<string, array{
     *         matches: int,
     *         yellows: int|null,
     *         reds: int|null,
     *         pens: int|null,
     *         ypm: float|null,
     *         rpm: float|null,
     *         ppm: float|null
     *     }>
     * }
     */
    private function demoRefereeStats(string $referee, int $id): array
    {
        $places = [
            'В. Мешков' => 'Россия · Санкт-Петербург',
            'С. Карасев' => 'Россия · Москва',
            'А. Матюнин' => 'Россия · Москва',
            'M. Oliver' => 'Англия · Чешир',
        ];

        $place = $places[$referee] ?? 'Россия';
        $seed = abs($id % 5);
        $minSample = 3;

        $build = static function (int $matches, int $yellows, int $reds, int $pens) use ($minSample): array {
            if ($matches < $minSample) {
                return [
                    'matches' => $matches,
                    'yellows' => null,
                    'reds' => null,
                    'pens' => null,
                    'ypm' => null,
                    'rpm' => null,
                    'ppm' => null,
                ];
            }

            return [
                'matches' => $matches,
                'yellows' => $yellows,
                'reds' => $reds,
                'pens' => $pens,
                'ypm' => round($yellows / $matches, 2),
                'rpm' => round($reds / $matches, 2),
                'ppm' => round($pens / $matches, 2),
            ];
        };

        // Три демо-набора: полный сезон, РПЛ, кубок с малой выборкой.
        $rplMatches = 12 + $seed;
        $rplY = 42 + $seed * 4;
        $rplR = 2 + ($seed % 3);
        $rplP = 4 + ($seed % 3);

        $cupMatches = 1 + ($id % 2); // 1–2 → прочерки
        $cupY = 3 + $seed;
        $cupR = $id % 2;
        $cupP = $id % 2;

        $allMatches = $rplMatches + $cupMatches;
        $allY = $rplY + $cupY;
        $allR = $rplR + $cupR;
        $allP = $rplP + $cupP;

        if ($referee === 'Назначение позже') {
            return [
                'name' => $referee,
                'place' => $place,
                'season' => '2025/26',
                'tabs' => [
                    ['id' => 'all', 'label' => 'Все'],
                    ['id' => 'rpl', 'label' => 'РПЛ'],
                    ['id' => 'cup', 'label' => 'Кубок России'],
                ],
                'samples' => [
                    'all' => $build(0, 0, 0, 0),
                    'rpl' => $build(0, 0, 0, 0),
                    'cup' => $build(0, 0, 0, 0),
                ],
            ];
        }

        return [
            'name' => $referee,
            'place' => $place,
            'season' => '2025/26',
            'tabs' => [
                ['id' => 'all', 'label' => 'Все'],
                ['id' => 'rpl', 'label' => 'РПЛ'],
                ['id' => 'cup', 'label' => 'Кубок России'],
            ],
            'samples' => [
                'all' => $build($allMatches, $allY, $allR, $allP),
                'rpl' => $build($rplMatches, $rplY, $rplR, $rplP),
                'cup' => $build($cupMatches, $cupY, $cupR, $cupP),
            ],
        ];
    }

    /**
     * Крупный прогноз 1X2 + топ correct score (из рынков / mock).
     *
     * @param  list<array<string, mixed>>  $markets
     * @return array{home_pct: int, draw_pct: int, away_pct: int, score_home: int, score_away: int, correct_scores: list<array{score: string, pct: int}>}
     */
    private function demoForecast(string $home, string $away, int $id, array $markets): array
    {
        $p1 = 42;
        $px = 27;
        $p2 = 31;
        if (isset($markets[0]['outcomes'][0]['prob'])) {
            $p1 = (int) $markets[0]['outcomes'][0]['prob'];
            $px = (int) $markets[0]['outcomes'][1]['prob'];
            $p2 = (int) $markets[0]['outcomes'][2]['prob'];
        }

        // Возможный счёт — в пользу фаворита 1X2.
        if ($p1 >= $p2 && $p1 >= $px) {
            $sh = 2;
            $sa = 1;
        } elseif ($p2 >= $p1 && $p2 >= $px) {
            $sh = 1;
            $sa = 2;
        } else {
            $sh = 1;
            $sa = 1;
        }

        if ($id === 101) {
            $sh = 2;
            $sa = 1;
        }

        $scores = [
            ['score' => "{$sh}:{$sa}", 'pct' => 14 + ($id % 4)],
            ['score' => '1:1', 'pct' => 11 + ($id % 3)],
            ['score' => '2:0', 'pct' => 9 + ($id % 3)],
            ['score' => '1:0', 'pct' => 8],
            ['score' => '0:1', 'pct' => 7],
            ['score' => '2:2', 'pct' => 6],
        ];
        usort($scores, static fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return [
            'home_pct' => $p1,
            'draw_pct' => $px,
            'away_pct' => $p2,
            'score_home' => $sh,
            'score_away' => $sa,
            'correct_scores' => array_slice($scores, 0, 5),
        ];
    }

    /**
     * Краткие факты под AI-превью.
     *
     * @return list<string>
     */
    private function demoInsights(string $home, string $away, int $id): array
    {
        $n = abs($id % 3);

        return [
            "{$home} забивают в ". (4 + $n).' из последних 5 домашних матчей.',
            "{$away} пропускают в среднем ". number_format(1.1 + $n * 0.2, 1, '.', '').' гола в гостях.',
            'В 3 из 5 очных встреч забивали обе команды.',
            'После 60′ у хозяев чаще второй гол, чем у гостей.',
            'Тотал 2.5 заходил в '. (55 + $n * 5).'% последних матчей обеих команд.',
            'Сухие матчи редки: чистый лист у любой стороны — менее чем в каждом третьем.',
        ];
    }

    /**
     * Тренды / серии для обеих команд.
     *
     * @return array{home: list<array<string, mixed>>, away: list<array<string, mixed>>}
     */
    private function demoTrends(string $home, string $away, int $id): array
    {
        $n = abs($id % 4);

        $side = static function (string $team, int $base) use ($n): array {
            return [
                [
                    'key' => 'scored',
                    'label' => 'Забьёт',
                    'value' => (string) (3 + $base + $n),
                    'unit' => 'подряд',
                    'hint' => 'матчи с голом',
                ],
                [
                    'key' => 'clean',
                    'label' => 'Сухие',
                    'value' => (string) (1 + ($base % 2)),
                    'unit' => 'из 5',
                    'hint' => 'чистый лист',
                ],
                [
                    'key' => 'btts',
                    'label' => 'Обе забьют',
                    'value' => (string) (2 + $n % 3),
                    'unit' => 'из 5',
                    'hint' => 'обе забили',
                ],
                [
                    'key' => 'over',
                    'label' => 'ТБ 2.5',
                    'value' => (string) (2 + (($base + $n) % 3)),
                    'unit' => 'из 5',
                    'hint' => 'тотал больше',
                ],
                [
                    'key' => 'under',
                    'label' => 'ТМ 2.5',
                    'value' => (string) (1 + (($base + 1) % 3)),
                    'unit' => 'из 5',
                    'hint' => 'тотал меньше',
                ],
            ];
        };

        return [
            'home' => $side($home, 1),
            'away' => $side($away, 0),
        ];
    }

    /**
     * Средние голы, HT-FT, тоталы по таймам.
     *
     * @return array<string, mixed>
     */
    private function demoGoalProfile(string $home, string $away, int $id): array
    {
        $n = ($id % 5) * 0.1;

        return [
            'averages' => [
                'home' => [
                    'team' => $home,
                    'gf' => round(1.7 + $n, 1),
                    'ga' => round(0.9 + $n * 0.5, 1),
                    'total' => round(2.6 + $n, 1),
                ],
                'away' => [
                    'team' => $away,
                    'gf' => round(1.3 + $n * 0.8, 1),
                    'ga' => round(1.2 + $n * 0.4, 1),
                    'total' => round(2.5 + $n * 0.7, 1),
                ],
                'match_exp' => round(2.7 + $n, 1),
            ],
            'ht_ft' => [
                ['label' => '1/1', 'pct' => 26 + ($id % 4)],
                ['label' => 'Н/1', 'pct' => 11],
                ['label' => '2/1', 'pct' => 6],
                ['label' => '1/Н', 'pct' => 8],
                ['label' => 'Н/Н', 'pct' => 10],
                ['label' => '2/Н', 'pct' => 7],
                ['label' => '1/2', 'pct' => 5],
                ['label' => 'Н/2', 'pct' => 9],
                ['label' => '2/2', 'pct' => 18 - ($id % 3)],
            ],
            'halves' => [
                'first' => [
                    'label' => '1-й тайм',
                    'avg' => round(1.1 + $n * 0.3, 1),
                    'over_05' => 74 + ($id % 5),
                    'over_15' => 36 + ($id % 6),
                ],
                'second' => [
                    'label' => '2-й тайм',
                    'avg' => round(1.5 + $n * 0.2, 1),
                    'over_05' => 81 + ($id % 4),
                    'over_15' => 48 + ($id % 5),
                ],
            ],
        ];
    }

    private function demoPreview(string $home, string $away, string $status): string
    {
        if ($status === 'finished') {
            return "Матч {$home} — {$away} уже сыгран. Ниже — снимок вероятностей на старт и то, как разошлись выборы сообщества. Сверка выбора с итогом — в блоке событий.";
        }

        if ($status === 'live') {
            return "Игра идёт: вероятности зафиксированы на старте. По ходу встречи {$home} и {$away} держат темп выше среднего — следите за счётом в шапке, выбор в рейтинг уже закрыт.";
        }

        return "{$home} дома обычно контролирует мяч и создаёт момент через фланги, {$away} отвечает быстрыми переходами и стандартами. Модель ждёт открытый матч с голами у обеих сторон и умеренным числом угловых. Карточки вероятнее во второй половине — прессинг гостей устаёт к 70-й.";
    }

    /**
     * Рынки в виде БК: группа исходов, % ИИ суммируются в 100.
     *
     * @param  array<string, mixed>  $match
     * @return list<array<string, mixed>>
     */
    private function demoMarketsForMatch(int $id, string $status, array $match): array
    {
        $seed = abs($id * 17 + 3);
        $nudge = static fn (int $base, int $i): int => max(8, min(92, $base + ((($seed + $i * 13) % 9) - 4)));
        $pop = static fn (int $base, int $i): int => max(3, min(55, $base + ((($seed + $i) % 5) - 2)));
        $fixed = $id === 101;

        $p1 = $fixed ? 42 : (int) ($match['prob_home'] ?? 42);
        $px = $fixed ? 27 : (int) ($match['prob_draw'] ?? 26);
        $p2 = $fixed ? 31 : (int) ($match['prob_away'] ?? 32);
        $sum1x2 = max(1, $p1 + $px + $p2);
        $p1 = (int) round($p1 * 100 / $sum1x2);
        $px = (int) round($px * 100 / $sum1x2);
        $p2 = 100 - $p1 - $px;

        $v = static function (int $base, int $i) use ($fixed, $nudge): int {
            return $fixed ? $base : $nudge($base, $i);
        };
        $p = static function (int $base, int $i) use ($fixed, $pop): int {
            return $fixed ? $base : $pop($base, $i);
        };

        // Двойной шанс: П1Н / П1П2 / НП2 — согласованы с 1X2.
        $dc1x = min(99, $p1 + $px);
        $dc12 = min(99, $p1 + $p2);
        $dcx2 = 100 - $p1; // ≈ px+p2

        $markets = [
            // —— Исходы ——
            $this->demoMarket('1x2', 'Исход матча', [
                ['id' => $id * 100 + 1, 'label' => 'П1', 'prob' => $p1, 'popular_pct' => $p(24, 0)],
                ['id' => $id * 100 + 2, 'label' => 'Н', 'prob' => $px, 'popular_pct' => $p(12, 1)],
                ['id' => $id * 100 + 3, 'label' => 'П2', 'prob' => $p2, 'popular_pct' => $p(19, 2)],
            ], 'results'),
            $this->demoMarket('dc', 'Двойной шанс', (static function () use ($id, $dc1x, $dc12, $dcx2, $p): array {
                $sum = max(1, $dc1x + $dc12 + $dcx2);
                $a = (int) round($dc1x * 100 / $sum);
                $b = (int) round($dc12 * 100 / $sum);
                $c = 100 - $a - $b;

                return [
                    ['id' => $id * 100 + 4, 'label' => '1X', 'prob' => $a, 'popular_pct' => $p(18, 3)],
                    ['id' => $id * 100 + 5, 'label' => '12', 'prob' => $b, 'popular_pct' => $p(14, 4)],
                    ['id' => $id * 100 + 6, 'label' => 'X2', 'prob' => $c, 'popular_pct' => $p(11, 5)],
                ];
            })(), 'results'),
            $this->demoBinaryMarket($id * 100 + 10, 'Обе забьют', $v(72, 6), $p(44, 6), $p(18, 7), 'Да', 'Нет', 'results'),
            $this->demoMarket('ah-1', 'Фора хозяев (−1.5)', [
                ['id' => $id * 100 + 14, 'label' => 'П1 (−1.5)', 'prob' => $v(38, 8), 'popular_pct' => $p(15, 8)],
                ['id' => $id * 100 + 15, 'label' => 'П2 (+1.5)', 'prob' => 100 - $v(38, 8), 'popular_pct' => $p(12, 9)],
            ], 'results'),

            // —— Тоталы ——
            $this->demoTotalMarket($id * 100 + 20, 'Тотал 1.5', $v(78, 10), $p(16, 10), $p(5, 11), null, 'ТБ', 'ТМ', 'totals'),
            $this->demoTotalMarket($id * 100 + 30, 'Тотал 2.5', $v(64, 12), $p(31, 12), $p(12, 13), null, 'ТБ', 'ТМ', 'totals'),
            $this->demoTotalMarket($id * 100 + 40, 'Тотал 3.5', $v(42, 14), $p(17, 14), $p(9, 15), null, 'ТБ', 'ТМ', 'totals'),
            $this->demoTotalMarket($id * 100 + 45, 'Тотал 1-го тайма 0.5', $v(74, 16), $p(14, 16), $p(6, 17), null, 'ТБ', 'ТМ', 'totals'),
            $this->demoTotalMarket($id * 100 + 48, 'Тотал 1-го тайма 1.5', $v(36, 18), $p(11, 18), $p(8, 19), null, 'ТБ', 'ТМ', 'totals'),
            $this->demoTotalMarket($id * 100 + 52, 'Тотал 2-го тайма 1.5', $v(48, 20), $p(13, 20), $p(7, 21), null, 'ТБ', 'ТМ', 'totals'),

            // —— Голы ——
            $this->demoBinaryMarket($id * 100 + 50, 'Хозяева забьют', $v(79, 22), $p(16, 22), $p(6, 23), 'Да', 'Нет', 'goals'),
            $this->demoBinaryMarket($id * 100 + 60, 'Гости забьют', $v(71, 24), $p(12, 24), $p(5, 25), 'Да', 'Нет', 'goals'),
            $this->demoTotalMarket($id * 100 + 64, 'Инд. тотал хозяев 1.5', $v(52, 26), $p(14, 26), $p(8, 27), null, 'ТБ', 'ТМ', 'goals'),
            $this->demoTotalMarket($id * 100 + 68, 'Инд. тотал гостей 1.5', $v(44, 28), $p(12, 28), $p(7, 29), null, 'ТБ', 'ТМ', 'goals'),
            $this->demoMarket('fg', 'Кто забьёт первым', [
                ['id' => $id * 100 + 72, 'label' => 'Хозяева', 'prob' => $v(48, 30), 'popular_pct' => $p(20, 30)],
                ['id' => $id * 100 + 73, 'label' => 'Гости', 'prob' => $v(37, 31), 'popular_pct' => $p(16, 31)],
                ['id' => $id * 100 + 74, 'label' => 'Без голов', 'prob' => 100 - $v(48, 30) - $v(37, 31), 'popular_pct' => $p(6, 32)],
            ], 'goals'),
            $this->demoBinaryMarket($id * 100 + 90, 'Тотал голов · чёт/нечёт', $v(48, 33), $p(7, 33), $p(5, 34), 'Чёт', 'Нечёт', 'goals'),

            // —— Угловые ——
            $this->demoTotalMarket($id * 100 + 100, 'Угловые 8.5', $v(68, 35), $p(14, 35), $p(7, 36), null, 'ТБ', 'ТМ', 'corners'),
            $this->demoTotalMarket($id * 100 + 70, 'Угловые 9.5', $v(61, 37), $p(15, 37), $p(8, 38), null, 'ТБ', 'ТМ', 'corners'),
            $this->demoTotalMarket($id * 100 + 104, 'Угловые 10.5', $v(47, 39), $p(12, 39), $p(7, 40), null, 'ТБ', 'ТМ', 'corners'),
            $this->demoTotalMarket($id * 100 + 108, 'Угловые хозяев 4.5', $v(54, 41), $p(11, 41), $p(6, 42), null, 'ТБ', 'ТМ', 'corners'),

            // —— ЖК ——
            $this->demoTotalMarket($id * 100 + 120, 'Жёлтые 3.5', $v(71, 43), $p(12, 43), $p(5, 44), null, 'ТБ', 'ТМ', 'yellows'),
            $this->demoTotalMarket($id * 100 + 80, 'Жёлтые 4.5', $v(55, 45), $p(10, 45), $p(6, 46), null, 'ТБ', 'ТМ', 'yellows'),
            $this->demoTotalMarket($id * 100 + 124, 'Жёлтые 5.5', $v(38, 47), $p(9, 47), $p(5, 48), null, 'ТБ', 'ТМ', 'yellows'),

            // —— КК ——
            $this->demoBinaryMarket($id * 100 + 140, 'Будет красная карточка', $v(28, 49), $p(9, 49), $p(14, 50), 'Да', 'Нет', 'reds'),
            $this->demoTotalMarket($id * 100 + 144, 'Красные 0.5', $v(28, 51), $p(8, 51), $p(13, 52), null, 'ТБ', 'ТМ', 'reds'),
        ];

        // Нормализуем тройные рынки (1X2, кто забьёт первым) к сумме 100.
        foreach ($markets as &$market) {
            if (! in_array($market['id'], ['1x2', 'fg'], true)) {
                continue;
            }
            $o = &$market['outcomes'];
            if (count($o) < 3) {
                unset($o);
                continue;
            }
            $s = max(1, $o[0]['prob'] + $o[1]['prob'] + $o[2]['prob']);
            $o[0]['prob'] = (int) round($o[0]['prob'] * 100 / $s);
            $o[1]['prob'] = (int) round($o[1]['prob'] * 100 / $s);
            $o[2]['prob'] = 100 - $o[0]['prob'] - $o[1]['prob'];
            unset($o);
        }
        unset($market);

        if ($status === 'finished') {
            foreach ($markets as &$market) {
                foreach ($market['outcomes'] as &$outcome) {
                    $outcome['hit'] = $outcome['prob'] >= 50
                        ? (($seed + $outcome['id']) % 3 !== 0)
                        : (($seed + $outcome['id']) % 4 === 0);
                }
                unset($outcome);
            }
            unset($market);
        }

        return $markets;
    }

    /**
     * @param  list<array{id: int, label: string, prob: int, popular_pct: int, hit?: bool|null}>  $outcomes
     * @return array{id: string, name: string, category: string, outcomes: list<array<string, mixed>>, popular_pct: int}
     */
    private function demoMarket(string $id, string $name, array $outcomes, string $category = 'results'): array
    {
        $normalized = [];
        foreach ($outcomes as $outcome) {
            $normalized[] = [
                'id' => $outcome['id'],
                'label' => $outcome['label'],
                'prob' => (int) $outcome['prob'],
                'popular_pct' => (int) ($outcome['popular_pct'] ?? 0),
                'hit' => $outcome['hit'] ?? null,
            ];
        }

        $popular = 0;
        foreach ($normalized as $outcome) {
            $popular = max($popular, $outcome['popular_pct']);
        }

        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'outcomes' => $normalized,
            'popular_pct' => $popular,
        ];
    }

    /**
     * Бинарный рынок: Да/Нет (или свои лейблы), p + (100−p).
     *
     * @return array{id: string, name: string, category: string, outcomes: list<array<string, mixed>>, popular_pct: int}
     */
    private function demoBinaryMarket(
        int $baseId,
        string $name,
        int $yesProb,
        int $yesPopular,
        int $noPopular,
        string $yesLabel = 'Да',
        string $noLabel = 'Нет',
        string $category = 'results',
    ): array {
        $yes = max(1, min(99, $yesProb));

        return $this->demoMarket((string) $baseId, $name, [
            ['id' => $baseId + 1, 'label' => $yesLabel, 'prob' => $yes, 'popular_pct' => $yesPopular],
            ['id' => $baseId + 2, 'label' => $noLabel, 'prob' => 100 - $yes, 'popular_pct' => $noPopular],
        ], $category);
    }

    /**
     * Тотал ТБ/ТМ: overProb + underProb = 100.
     *
     * @return array{id: string, name: string, category: string, outcomes: list<array<string, mixed>>, popular_pct: int}
     */
    private function demoTotalMarket(
        int $baseId,
        string $name,
        int $overProb,
        int $overPopular,
        int $underPopular,
        ?int $underProb = null,
        string $overLabel = 'ТБ',
        string $underLabel = 'ТМ',
        string $category = 'totals',
    ): array {
        $over = max(1, min(99, $overProb));
        $under = $underProb === null ? (100 - $over) : max(1, min(99, $underProb));
        if ($underProb !== null && ($over + $under) !== 100) {
            $under = 100 - $over;
        }

        return $this->demoMarket((string) $baseId, $name, [
            ['id' => $baseId + 1, 'label' => $overLabel, 'prob' => $over, 'popular_pct' => $overPopular],
            ['id' => $baseId + 2, 'label' => $underLabel, 'prob' => $under, 'popular_pct' => $underPopular],
        ], $category);
    }

    /**
     * Сравнение last-5 (или доли по таймам) с номинальной шкалой `scale`.
     *
     * @return list<array{key: string, label: string, home: float|int, away: float|int, scale: float|int, unit?: string}>
     */
    private function demoComparison(string $home, string $away, int $id, string $period = 'match'): array
    {
        $n = abs($id % 7);
        // Таймы — урезанные/смещённые средние (mock), матч = полный профиль.
        [$fHome, $fAway] = match ($period) {
            'first_half' => [0.48, 0.42],
            'second_half' => [0.52, 0.58],
            default => [1.0, 1.0],
        };

        $round1 = static fn (float $v): float => round($v, 1);
        $goalsH = $round1((1.8 + ($n % 3) * 0.2) * $fHome);
        $goalsA = $round1((1.4 + ($n % 2) * 0.3) * $fAway);
        $xgH = $round1((1.65 + ($n % 4) * 0.1) * $fHome);
        $xgA = $round1((1.35 + ($n % 3) * 0.1) * $fAway);
        $shotsH = (int) round((14 + $n) * $fHome);
        $shotsA = (int) round((11 + ($n % 5)) * $fAway);
        $sotH = $round1((5.2 + ($n % 3) * 0.4) * $fHome);
        $sotA = $round1((4.1 + ($n % 2) * 0.5) * $fAway);
        $corH = $round1((5.4 + ($n % 3) * 0.3) * $fHome);
        $corA = $round1((4.6 + ($n % 4) * 0.2) * $fAway);
        $yelH = $round1((1.8 + ($n % 2) * 0.2) * ($period === 'match' ? 1 : 0.55));
        $yelA = $round1((2.2 + ($n % 3) * 0.2) * ($period === 'match' ? 1 : 0.55));
        $foulsH = (int) round((11 + ($n % 4)) * ($period === 'match' ? 1 : 0.5));
        $foulsA = (int) round((13 + ($n % 3)) * ($period === 'match' ? 1 : 0.5));

        $possH = (int) round(54 + ($n % 5) + ($period === 'first_half' ? 3 : ($period === 'second_half' ? -2 : 0)));
        $possH = max(35, min(65, $possH));
        $possA = 100 - $possH;

        $bttsH = max(20, min(90, (int) round((60 + ($n % 4) * 5) * ($period === 'match' ? 1 : 0.9))));
        $bttsA = max(20, min(90, (int) round((55 + ($n % 3) * 5) * ($period === 'match' ? 1 : 0.85))));
        $csH = max(10, min(70, (int) round((35 + ($n % 3) * 5) * ($period === 'first_half' ? 1.1 : 1))));
        $csA = max(10, min(70, (int) round((25 + ($n % 4) * 5) * ($period === 'second_half' ? 0.9 : 1))));

        return [
            ['key' => 'goals', 'label' => 'Голы', 'home' => $goalsH, 'away' => $goalsA, 'scale' => 3],
            ['key' => 'xg', 'label' => 'xG', 'home' => $xgH, 'away' => $xgA, 'scale' => 2.5],
            ['key' => 'shots', 'label' => 'Удары', 'home' => $shotsH, 'away' => $shotsA, 'scale' => 20],
            ['key' => 'sot', 'label' => 'В створ', 'home' => $sotH, 'away' => $sotA, 'scale' => 8],
            ['key' => 'corners', 'label' => 'Угловые', 'home' => $corH, 'away' => $corA, 'scale' => 8],
            ['key' => 'yellows', 'label' => 'Жёлтые', 'home' => $yelH, 'away' => $yelA, 'scale' => 4],
            ['key' => 'fouls', 'label' => 'Фолы', 'home' => $foulsH, 'away' => $foulsA, 'scale' => 18],
            ['key' => 'possession', 'label' => 'Владение %', 'home' => $possH, 'away' => $possA, 'scale' => 100, 'unit' => '%'],
            ['key' => 'btts', 'label' => 'Обе забьют %', 'home' => $bttsH, 'away' => $bttsA, 'scale' => 100, 'unit' => '%'],
            ['key' => 'cs', 'label' => 'Сухие %', 'home' => $csH, 'away' => $csA, 'scale' => 100, 'unit' => '%'],
        ];
    }

    /**
     * Home/away split averages — 3 выборки: турнир / last 5 / last 10.
     *
     * @return array{tournament: array, last5: array, last10: array}
     */
    private function demoHomeAwaySplits(string $home, string $away, int $id): array
    {
        return [
            'tournament' => $this->demoHomeAwaySample($home, $away, $id, 'tournament'),
            'last5' => $this->demoHomeAwaySample($home, $away, $id, 'last5'),
            'last10' => $this->demoHomeAwaySample($home, $away, $id, 'last10'),
        ];
    }

    /**
     * @return array{home: array<string, mixed>, away: array<string, mixed>}
     */
    private function demoHomeAwaySample(string $home, string $away, int $id, string $sample): array
    {
        $n = abs($id % 5);
        // Турнир — длинная выборка (ближе к «среднему сезону»);
        // 5 матчей — более «горячие» цифры; 10 — посередине.
        [$boostH, $boostA] = match ($sample) {
            'last5' => [1.12, 1.08],
            'last10' => [1.05, 1.03],
            default => [1.0, 1.0],
        };

        $r1 = static fn (float $v): float => round($v, 1);

        return [
            'home' => [
                'team' => $home,
                'context' => 'дома',
                'gf' => $r1((1.9 + $n * 0.1) * $boostH),
                'ga' => $r1((0.9 + ($n % 3) * 0.1) * ($sample === 'last5' ? 0.92 : 1.0)),
                'xg' => $r1((1.7 + $n * 0.05) * $boostH),
                'corners' => $r1((5.6 + ($n % 3) * 0.2) * $boostH),
                'yellows' => $r1((1.6 + ($n % 3) * 0.2) * ($sample === 'tournament' ? 1.0 : 1.1)),
                'sot' => $r1((5.4 + ($n % 3) * 0.3) * $boostH),
                'fouls' => (int) round((11 + ($n % 4)) * ($sample === 'last5' ? 1.08 : 1.0)),
                'offsides' => $r1((1.8 + ($n % 2) * 0.3) * $boostH),
            ],
            'away' => [
                'team' => $away,
                'context' => 'в гостях',
                'gf' => $r1((1.3 + ($n % 3) * 0.1) * $boostA),
                'ga' => $r1((1.4 + ($n % 2) * 0.2) * ($sample === 'last5' ? 1.05 : 1.0)),
                'xg' => $r1((1.2 + ($n % 4) * 0.08) * $boostA),
                'corners' => $r1((4.2 + ($n % 3) * 0.3) * $boostA),
                'yellows' => $r1((2.1 + ($n % 3) * 0.2) * ($sample === 'tournament' ? 1.0 : 1.08)),
                'sot' => $r1((4.0 + ($n % 2) * 0.4) * $boostA),
                'fouls' => (int) round((13 + ($n % 3)) * ($sample === 'last10' ? 1.04 : 1.0)),
                'offsides' => $r1((2.2 + ($n % 3) * 0.2) * $boostA),
            ],
        ];
    }

    /**
     * Мини-таблица: И, В, Н, ЗП, ПР, +/−, О + highlight обеих команд матча.
     *
     * @return list<array{pos: int, team: string, played: int, wins: int, draws: int, gf: int, ga: int, gd: int, pts: int, highlight: bool}>
     */
    private function demoMiniStandings(string $home, string $away, string $leagueId): array
    {
        if ($leagueId === 'ucl') {
            $rows = [
                ['pos' => 1, 'team' => 'Bayern', 'played' => 4, 'wins' => 3, 'draws' => 1, 'gf' => 10, 'ga' => 3, 'gd' => 7, 'pts' => 10],
                ['pos' => 2, 'team' => 'Real Madrid', 'played' => 4, 'wins' => 3, 'draws' => 0, 'gf' => 9, 'ga' => 4, 'gd' => 5, 'pts' => 9],
                ['pos' => 3, 'team' => 'Man City', 'played' => 4, 'wins' => 2, 'draws' => 2, 'gf' => 8, 'ga' => 4, 'gd' => 4, 'pts' => 8],
                ['pos' => 4, 'team' => 'Inter', 'played' => 4, 'wins' => 2, 'draws' => 1, 'gf' => 7, 'ga' => 4, 'gd' => 3, 'pts' => 7],
                ['pos' => 5, 'team' => 'Arsenal', 'played' => 4, 'wins' => 2, 'draws' => 1, 'gf' => 6, 'ga' => 4, 'gd' => 2, 'pts' => 7],
                ['pos' => 6, 'team' => 'PSG', 'played' => 4, 'wins' => 2, 'draws' => 0, 'gf' => 5, 'ga' => 4, 'gd' => 1, 'pts' => 6],
            ];
        } elseif ($leagueId === 'fnl') {
            $rows = [
                ['pos' => 1, 'team' => 'Балтика', 'played' => 16, 'wins' => 10, 'draws' => 2, 'gf' => 28, 'ga' => 16, 'gd' => 12, 'pts' => 32],
                ['pos' => 2, 'team' => 'Урал', 'played' => 16, 'wins' => 9, 'draws' => 2, 'gf' => 24, 'ga' => 16, 'gd' => 8, 'pts' => 29],
                ['pos' => 3, 'team' => 'Черноморец', 'played' => 16, 'wins' => 8, 'draws' => 3, 'gf' => 22, 'ga' => 17, 'gd' => 5, 'pts' => 27],
                ['pos' => 4, 'team' => 'Ротор', 'played' => 16, 'wins' => 7, 'draws' => 3, 'gf' => 19, 'ga' => 17, 'gd' => 2, 'pts' => 24],
                ['pos' => 5, 'team' => 'СКА-Хабаровск', 'played' => 16, 'wins' => 6, 'draws' => 4, 'gf' => 18, 'ga' => 18, 'gd' => 0, 'pts' => 22],
                ['pos' => 6, 'team' => 'Нефтехимик', 'played' => 16, 'wins' => 5, 'draws' => 5, 'gf' => 17, 'ga' => 18, 'gd' => -1, 'pts' => 20],
            ];
        } else {
            $rows = [
                ['pos' => 1, 'team' => 'Зенит', 'played' => 14, 'wins' => 11, 'draws' => 1, 'gf' => 32, 'ga' => 14, 'gd' => 18, 'pts' => 34],
                ['pos' => 2, 'team' => 'Краснодар', 'played' => 14, 'wins' => 9, 'draws' => 4, 'gf' => 28, 'ga' => 14, 'gd' => 14, 'pts' => 31],
                ['pos' => 3, 'team' => 'Спартак', 'played' => 14, 'wins' => 8, 'draws' => 4, 'gf' => 26, 'ga' => 16, 'gd' => 10, 'pts' => 28],
                ['pos' => 4, 'team' => 'ЦСКА', 'played' => 14, 'wins' => 7, 'draws' => 5, 'gf' => 24, 'ga' => 16, 'gd' => 8, 'pts' => 26],
                ['pos' => 5, 'team' => 'Динамо', 'played' => 14, 'wins' => 7, 'draws' => 3, 'gf' => 22, 'ga' => 17, 'gd' => 5, 'pts' => 24],
                ['pos' => 6, 'team' => 'Локомотив', 'played' => 14, 'wins' => 6, 'draws' => 4, 'gf' => 20, 'ga' => 17, 'gd' => 3, 'pts' => 22],
                ['pos' => 7, 'team' => 'Ростов', 'played' => 14, 'wins' => 5, 'draws' => 5, 'gf' => 18, 'ga' => 17, 'gd' => 1, 'pts' => 20],
                ['pos' => 8, 'team' => 'Рубин', 'played' => 14, 'wins' => 5, 'draws' => 3, 'gf' => 16, 'ga' => 18, 'gd' => -2, 'pts' => 18],
            ];
        }

        // Гарантируем, что обе команды видны: если нет — подмешиваем рядом.
        $names = collect($rows)->pluck('team');
        if (! $names->contains($home)) {
            $rows[] = [
                'pos' => count($rows) + 1,
                'team' => $home,
                'played' => 14,
                'wins' => 4,
                'draws' => 4,
                'gf' => 16,
                'ga' => 16,
                'gd' => 0,
                'pts' => 16,
            ];
        }
        if (! $names->contains($away) && $away !== $home) {
            $rows[] = [
                'pos' => count($rows) + 1,
                'team' => $away,
                'played' => 14,
                'wins' => 4,
                'draws' => 2,
                'gf' => 14,
                'ga' => 15,
                'gd' => -1,
                'pts' => 14,
            ];
        }

        $homePos = null;
        $awayPos = null;
        foreach ($rows as $row) {
            if ($row['team'] === $home) {
                $homePos = $row['pos'];
            }
            if ($row['team'] === $away) {
                $awayPos = $row['pos'];
            }
        }

        $focus = array_values(array_filter(
            [$homePos, $awayPos],
            static fn ($p) => $p !== null,
        ));
        $minPos = $focus === [] ? 1 : max(1, min($focus) - 1);
        $maxPos = $focus === [] ? 6 : min(count($rows), max($focus) + 1);

        $slice = array_values(array_filter(
            $rows,
            static fn ($row) => $row['pos'] >= $minPos && $row['pos'] <= $maxPos,
        ));

        // Слева — старше, справа — свежее; буквы В/Н/П.
        $formBank = [
            ['В', 'В', 'Н', 'П', 'В'],
            ['В', 'Н', 'В', 'П', 'В'],
            ['П', 'В', 'В', 'В', 'Н'],
            ['Н', 'В', 'В', 'П', 'В'],
            ['В', 'П', 'В', 'Н', 'П'],
            ['П', 'В', 'В', 'Н', 'В'],
            ['В', 'Н', 'П', 'В', 'В'],
            ['Н', 'П', 'В', 'В', 'П'],
        ];

        return array_map(static function (array $row) use ($home, $away, $formBank) {
            $row['highlight'] = $row['team'] === $home || $row['team'] === $away;
            $row['form'] = $formBank[($row['pos'] - 1) % count($formBank)];
            $played = max(1, (int) $row['played']);
            $row['ppg'] = round(((int) $row['pts']) / $played, 2);

            return $row;
        }, $slice);
    }

    /**
     * Топ-5 бомбардиров команды: голы, передачи, пенальти (входят в голы), Г+П.
     *
     * @return list<array{player: string, goals: int, assists: int, pens: int, ga: int}>
     */
    private function demoTeamScorers(string $team, int $id, string $side): array
    {
        $catalog = [
            'Зенит' => [
                ['player' => 'М. Глушенков', 'goals' => 11, 'assists' => 4, 'pens' => 2],
                ['player' => 'М. Кассьерра', 'goals' => 8, 'assists' => 2, 'pens' => 1],
                ['player' => 'Вендел', 'goals' => 5, 'assists' => 6, 'pens' => 0],
                ['player' => 'А. Мостовой', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'Н. Гонду', 'goals' => 3, 'assists' => 2, 'pens' => 1],
            ],
            'Спартак' => [
                ['player' => 'Т. Барко', 'goals' => 8, 'assists' => 5, 'pens' => 1],
                ['player' => 'Э. Промес', 'goals' => 6, 'assists' => 3, 'pens' => 2],
                ['player' => 'М. Угальде', 'goals' => 5, 'assists' => 2, 'pens' => 0],
                ['player' => 'Х. Медина', 'goals' => 4, 'assists' => 4, 'pens' => 0],
                ['player' => 'Р. Литвинов', 'goals' => 3, 'assists' => 3, 'pens' => 0],
            ],
            'ЦСКА' => [
                ['player' => 'И. Обляков', 'goals' => 7, 'assists' => 4, 'pens' => 1],
                ['player' => 'В. Давила', 'goals' => 5, 'assists' => 2, 'pens' => 0],
                ['player' => 'Т. Мусаев', 'goals' => 4, 'assists' => 1, 'pens' => 1],
                ['player' => 'С. Гуардадо', 'goals' => 3, 'assists' => 5, 'pens' => 0],
                ['player' => 'К. Фаядов', 'goals' => 3, 'assists' => 2, 'pens' => 0],
            ],
            'Динамо' => [
                ['player' => 'К. Тюкавин', 'goals' => 7, 'assists' => 3, 'pens' => 2],
                ['player' => 'Б. Захарян', 'goals' => 4, 'assists' => 5, 'pens' => 0],
                ['player' => 'Я. Гладышев', 'goals' => 4, 'assists' => 2, 'pens' => 0],
                ['player' => 'Н. Моро', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'Л. Карраскаль', 'goals' => 2, 'assists' => 3, 'pens' => 1],
            ],
            'Краснодар' => [
                ['player' => 'Дж. Кордоба', 'goals' => 9, 'assists' => 2, 'pens' => 2],
                ['player' => 'Э. Сперанцон', 'goals' => 6, 'assists' => 4, 'pens' => 0],
                ['player' => 'К. Олусегун', 'goals' => 5, 'assists' => 1, 'pens' => 1],
                ['player' => 'А. Кривцов', 'goals' => 3, 'assists' => 3, 'pens' => 0],
                ['player' => 'Э. Ахметов', 'goals' => 2, 'assists' => 4, 'pens' => 0],
            ],
            'Локомотив' => [
                ['player' => 'Т. Батраков', 'goals' => 7, 'assists' => 3, 'pens' => 1],
                ['player' => 'Д. Воробьёв', 'goals' => 5, 'assists' => 2, 'pens' => 2],
                ['player' => 'С. Пиняев', 'goals' => 4, 'assists' => 5, 'pens' => 0],
                ['player' => 'Н. Тикнизян', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'А. Баринов', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
            'Ростов' => [
                ['player' => 'Р. Голенков', 'goals' => 6, 'assists' => 2, 'pens' => 1],
                ['player' => 'Е. Голенков', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'К. Щетинин', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'Н. Комличенко', 'goals' => 5, 'assists' => 1, 'pens' => 2],
                ['player' => 'И. Вахания', 'goals' => 2, 'assists' => 2, 'pens' => 0],
            ],
            'Рубин' => [
                ['player' => 'М. Дрейер', 'goals' => 6, 'assists' => 2, 'pens' => 1],
                ['player' => 'Д. Кабутов', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'И. Васильевич', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'А. Зотове', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'Н. Кузяев', 'goals' => 2, 'assists' => 1, 'pens' => 1],
            ],
            'Крылья Советов' => [
                ['player' => 'Б. Гарре', 'goals' => 7, 'assists' => 3, 'pens' => 1],
                ['player' => 'И. Сергеев', 'goals' => 5, 'assists' => 1, 'pens' => 2],
                ['player' => 'Ф. Ороско', 'goals' => 4, 'assists' => 4, 'pens' => 0],
                ['player' => 'А. Рахманович', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'Д. Цуприн', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
            'Пари НН' => [
                ['player' => 'З. Кехрия', 'goals' => 6, 'assists' => 2, 'pens' => 1],
                ['player' => 'А. Емельянов', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'Н. Калинский', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'Д. Стрельцов', 'goals' => 3, 'assists' => 1, 'pens' => 1],
                ['player' => 'И. Маевский', 'goals' => 2, 'assists' => 2, 'pens' => 0],
            ],
            'Оренбург' => [
                ['player' => 'Д. Воробьёв', 'goals' => 5, 'assists' => 2, 'pens' => 1],
                ['player' => 'Д. Пасич', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'Д. Гогулев', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'А. Марихин', 'goals' => 3, 'assists' => 1, 'pens' => 1],
                ['player' => 'К. Капленко', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
            'Ахмат' => [
                ['player' => 'М. Коновалов', 'goals' => 5, 'assists' => 2, 'pens' => 1],
                ['player' => 'Л. Садулаев', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'А. Адамов', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'Н. Феликс', 'goals' => 3, 'assists' => 1, 'pens' => 1],
                ['player' => 'И. Бершадский', 'goals' => 2, 'assists' => 2, 'pens' => 0],
            ],
            'Балтика' => [
                ['player' => 'А. Дутов', 'goals' => 6, 'assists' => 2, 'pens' => 1],
                ['player' => 'Г. Лисакович', 'goals' => 5, 'assists' => 1, 'pens' => 2],
                ['player' => 'К. Ковалёв', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'Д. Радисавлевич', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'Н. Титов', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
            'Урал' => [
                ['player' => 'А. Бегич', 'goals' => 5, 'assists' => 2, 'pens' => 1],
                ['player' => 'Е. Аверьянов', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'И. Помазун', 'goals' => 0, 'assists' => 1, 'pens' => 0],
                ['player' => 'Д. Мишкич', 'goals' => 3, 'assists' => 2, 'pens' => 0],
                ['player' => 'А. Емельянов', 'goals' => 3, 'assists' => 4, 'pens' => 1],
            ],
            'Real Madrid' => [
                ['player' => 'К. Мбаппе', 'goals' => 9, 'assists' => 3, 'pens' => 2],
                ['player' => 'В. Жуниор', 'goals' => 6, 'assists' => 5, 'pens' => 0],
                ['player' => 'Дж. Беллингем', 'goals' => 4, 'assists' => 4, 'pens' => 0],
                ['player' => 'Р. Диас', 'goals' => 3, 'assists' => 2, 'pens' => 1],
                ['player' => 'Ф. Вальверде', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
            'Man City' => [
                ['player' => 'Э. Холанн', 'goals' => 11, 'assists' => 2, 'pens' => 3],
                ['player' => 'Ф. Фоден', 'goals' => 5, 'assists' => 4, 'pens' => 0],
                ['player' => 'Х. Альварес', 'goals' => 4, 'assists' => 3, 'pens' => 1],
                ['player' => 'К. Де Брёйне', 'goals' => 2, 'assists' => 6, 'pens' => 0],
                ['player' => 'Б. Силва', 'goals' => 3, 'assists' => 2, 'pens' => 0],
            ],
            'Bayern' => [
                ['player' => 'Г. Кане', 'goals' => 10, 'assists' => 3, 'pens' => 2],
                ['player' => 'Дж. Мусиала', 'goals' => 5, 'assists' => 5, 'pens' => 0],
                ['player' => 'Л. Сане', 'goals' => 4, 'assists' => 3, 'pens' => 0],
                ['player' => 'Т. Мюллер', 'goals' => 3, 'assists' => 4, 'pens' => 0],
                ['player' => 'М. Олисе', 'goals' => 3, 'assists' => 2, 'pens' => 1],
            ],
            'PSG' => [
                ['player' => 'О. Дембеле', 'goals' => 8, 'assists' => 4, 'pens' => 1],
                ['player' => 'Б. Баркола', 'goals' => 5, 'assists' => 3, 'pens' => 0],
                ['player' => 'В. Перейра', 'goals' => 4, 'assists' => 2, 'pens' => 1],
                ['player' => 'Ф. Руис', 'goals' => 3, 'assists' => 5, 'pens' => 0],
                ['player' => 'А. Акали', 'goals' => 2, 'assists' => 2, 'pens' => 0],
            ],
            'Inter' => [
                ['player' => 'Л. Мартинес', 'goals' => 9, 'assists' => 2, 'pens' => 2],
                ['player' => 'М. Тюрам', 'goals' => 6, 'assists' => 3, 'pens' => 0],
                ['player' => 'Н. Барелла', 'goals' => 3, 'assists' => 5, 'pens' => 0],
                ['player' => 'Х. Мхитарян', 'goals' => 2, 'assists' => 4, 'pens' => 0],
                ['player' => 'Ф. Димерко', 'goals' => 4, 'assists' => 1, 'pens' => 1],
            ],
            'Arsenal' => [
                ['player' => 'Б. Сака', 'goals' => 7, 'assists' => 5, 'pens' => 1],
                ['player' => 'К. Ака', 'goals' => 6, 'assists' => 2, 'pens' => 0],
                ['player' => 'М. Эдегор', 'goals' => 3, 'assists' => 6, 'pens' => 0],
                ['player' => 'Г. Жезус', 'goals' => 4, 'assists' => 2, 'pens' => 1],
                ['player' => 'Д. Райс', 'goals' => 2, 'assists' => 3, 'pens' => 0],
            ],
        ];

        $rows = $catalog[$team] ?? $this->demoFallbackScorers($team, $id, $side);

        $normalized = [];
        foreach ($rows as $row) {
            $goals = (int) ($row['goals'] ?? 0);
            $assists = (int) ($row['assists'] ?? 0);
            $pens = min($goals, (int) ($row['pens'] ?? 0));
            $normalized[] = [
                'player' => (string) $row['player'],
                'goals' => $goals,
                'assists' => $assists,
                'pens' => $pens,
                'ga' => $goals + $assists,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            if ($a['ga'] !== $b['ga']) {
                return $b['ga'] <=> $a['ga'];
            }
            if ($a['goals'] !== $b['goals']) {
                return $b['goals'] <=> $a['goals'];
            }

            return $b['assists'] <=> $a['assists'];
        });

        return array_slice($normalized, 0, 5);
    }

    /**
     * @return list<array{player: string, goals: int, assists: int, pens: int}>
     */
    private function demoFallbackScorers(string $team, int $id, string $side): array
    {
        $names = [
            'А. Иванов', 'Д. Смирнов', 'И. Кузнецов', 'М. Попов', 'Н. Соколов',
            'С. Лебедев', 'П. Козлов', 'Е. Новиков', 'В. Морозов', 'К. Петров',
            'Р. Волков', 'Т. Алексеев', 'Ю. Фёдоров', 'Л. Михайлов', 'Г. Орлов',
        ];
        $offset = abs(($id * 3) + ($side === 'home' ? 0 : 7)) % count($names);
        $base = 5 + ($id % 4);

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $goals = max(1, $base - $i);
            $assists = max(0, 4 - intdiv($i, 1) + (($id + $i) % 3));
            $pens = $i === 0 ? min(2, $goals) : ($i === 1 ? min(1, $goals) : 0);
            $rows[] = [
                'player' => $names[($offset + $i) % count($names)],
                'goals' => $goals,
                'assists' => $assists,
                'pens' => $pens,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{player: string, reason: string, status: string}>
     */
    private function demoAbsences(string $team, int $id, string $side): array
    {
        $catalog = [
            'Зенит' => [
                ['player' => 'Н. Отуде', 'reason' => 'Травма мышцы', 'status' => 'out'],
                ['player' => 'Д. Ерохин', 'reason' => 'Сомнительно', 'status' => 'doubt'],
            ],
            'Спартак' => [
                ['player' => 'А. Рябков', 'reason' => 'Дисквалификация', 'status' => 'out'],
                ['player' => 'К. Мартинс', 'reason' => 'Микротравма', 'status' => 'doubt'],
            ],
            'ЦСКА' => [
                ['player' => 'М. Гайич', 'reason' => 'Травма', 'status' => 'out'],
            ],
            'Динамо' => [
                ['player' => 'Р. Фернандес', 'reason' => 'Сомнительно', 'status' => 'doubt'],
            ],
        ];

        if (isset($catalog[$team])) {
            return $catalog[$team];
        }

        if ($id % 3 === 0) {
            return [];
        }

        return [
            [
                'player' => $side === 'home' ? "Игрок {$team}" : "Запасной {$team}",
                'reason' => $id % 2 === 0 ? 'Травма' : 'Дисквалификация',
                'status' => $id % 2 === 0 ? 'out' : 'doubt',
            ],
        ];
    }

    /**
     * Средние голы по отрезкам минут (home vs away).
     *
     * @return list<array{band: string, home: float, away: float}>
     */
    /**
     * Суммы голов по интервалам (не средние) + номинальная шкала `scale`.
     *
     * @return list<array{band: string, home: int, away: int, scale: int}>
     */
    private function demoGoalMinutes(int $id): array
    {
        $n = abs($id % 4);
        $scale = 12;

        return [
            ['band' => '0–15', 'home' => 5 + $n, 'away' => 4, 'scale' => $scale],
            ['band' => '15–30', 'home' => 7, 'away' => 5 + $n, 'scale' => $scale],
            ['band' => '30–45', 'home' => 8 + $n, 'away' => 6, 'scale' => $scale],
            ['band' => '45–60', 'home' => 6, 'away' => 7 + $n, 'scale' => $scale],
            ['band' => '60–75', 'home' => 9 + $n, 'away' => 5, 'scale' => $scale],
            ['band' => '75–90', 'home' => 10, 'away' => 8 + $n, 'scale' => $scale],
        ];
    }

    /**
     * 5 блоков формы: H2H, хозяева, гости, хозяева дома, гости в гостях.
     * На матч: дата, пары, FT/HT счёт, турнир, result (В/Н/П относительно focus;
     * для H2H — хозяева предстоящего матча).
     *
     * @return list<array{id: string, title: string, focus: string, matches: list<array<string, mixed>>}>
     */
    private function demoFormBlocks(string $home, string $away, int $id, string $leagueShort = 'РПЛ'): array
    {
        $pool = array_values(array_filter(
            ['ЦСКА', 'Краснодар', 'Локомотив', 'Динамо', 'Ростов', 'Рубин', 'Ахмат', 'Крылья Советов', 'Пари НН', 'Оренбург', 'Man City', 'Bayern', 'Arsenal', 'Inter'],
            fn (string $t) => $t !== $home && $t !== $away,
        ));

        $shift = abs($id % 5);
        $cup = $leagueShort === 'UCL' ? 'СК' : 'КР';
        $comps = [$leagueShort, $cup, $leagueShort, $leagueShort, $cup];

        $row = function (
            int $daysAgo,
            string $homeTeam,
            string $awayTeam,
            int $sh,
            int $sa,
            string $focus,
            int $compIdx = 0,
        ) use ($comps): array {
            if ($sh === $sa) {
                $result = 'Н';
            } elseif ($focus === $homeTeam) {
                $result = $sh > $sa ? 'В' : 'П';
            } else {
                $result = $sa > $sh ? 'В' : 'П';
            }

            $htHome = (int) min($sh, max(0, intdiv($sh + ($daysAgo % 3), 2)));
            $htAway = (int) min($sa, max(0, intdiv($sa + (($daysAgo + 1) % 3), 2)));

            return [
                'date' => now()->subDays($daysAgo)->toDateString(),
                'home' => $homeTeam,
                'away' => $awayTeam,
                'score_home' => $sh,
                'score_away' => $sa,
                'ht_home' => $htHome,
                'ht_away' => $htAway,
                'competition' => $comps[$compIdx % count($comps)],
                'result' => $result,
            ];
        };

        $opp = fn (int $i) => $pool[($i + $shift) % count($pool)];

        // 1. H2H — последние 5 между этими командами (focus = хозяева предстоящего)
        $h2h = [
            $row(28 + $shift, $away, $home, 1, 2, $home, 0),
            $row(90 + $shift, $home, $away, 2, 2, $home, 1),
            $row(160 + $shift, $away, $home, 0, 1, $home, 2),
            $row(250 + $shift, $home, $away, 3, 1, $home, 3),
            $row(340 + $shift, $away, $home, 2, 0, $home, 4),
        ];

        // 2. Последние 5 хозяев (все матчи)
        $homeAll = [
            $row(7, $home, $opp(0), 2, 1, $home, 0),
            $row(14, $opp(1), $home, 1, 1, $home, 1),
            $row(21, $home, $opp(2), 3, 0, $home, 2),
            $row(28, $opp(3), $home, 2, 0, $home, 3),
            $row(35, $home, $opp(4), 1, 0, $home, 4),
        ];

        // 3. Последние 5 гостей
        $awayAll = [
            $row(6, $away, $opp(2), 1, 0, $away, 0),
            $row(13, $opp(0), $away, 2, 2, $away, 1),
            $row(20, $away, $opp(4), 0, 2, $away, 2),
            $row(27, $opp(1), $away, 1, 3, $away, 3),
            $row(34, $away, $opp(3), 2, 1, $away, 4),
        ];

        // 4. Последние 5 хозяев дома
        $homeHome = [
            $row(7, $home, $opp(0), 2, 1, $home, 0),
            $row(21, $home, $opp(2), 3, 0, $home, 1),
            $row(35, $home, $opp(4), 1, 0, $home, 2),
            $row(49, $home, $opp(1), 2, 2, $home, 3),
            $row(63, $home, $opp(3), 0, 1, $home, 4),
        ];

        // 5. Последние 5 гостей в гостях
        $awayAway = [
            $row(13, $opp(0), $away, 2, 2, $away, 0),
            $row(27, $opp(1), $away, 1, 3, $away, 1),
            $row(41, $opp(4), $away, 0, 1, $away, 2),
            $row(55, $opp(2), $away, 2, 0, $away, 3),
            $row(69, $opp(3), $away, 1, 1, $away, 4),
        ];

        return [
            [
                'id' => 'h2h',
                'title' => 'Последние 5 между этими командами',
                'focus' => $home,
                'matches' => $h2h,
            ],
            [
                'id' => 'home_all',
                'title' => 'Последние 5 · '.$home,
                'focus' => $home,
                'matches' => $homeAll,
            ],
            [
                'id' => 'away_all',
                'title' => 'Последние 5 · '.$away,
                'focus' => $away,
                'matches' => $awayAll,
            ],
            [
                'id' => 'home_home',
                'title' => 'Последние 5 · '.$home.' дома',
                'focus' => $home,
                'matches' => $homeHome,
            ],
            [
                'id' => 'away_away',
                'title' => 'Последние 5 · '.$away.' в гостях',
                'focus' => $away,
                'matches' => $awayAway,
            ],
        ];
    }

    /**
     * @return list<array{id: int, author: string, body: string, ago: string, likes: int}>
     */
    private function demoComments(string $home, string $away, int $id, bool $started): array
    {
        $comments = [
            [
                'id' => $id * 100 + 1,
                'author' => 'Игорь_RPL',
                'body' => "Беру обе забьют — у {$away} контратаки слишком острые для сухой обороны {$home}.",
                'ago' => '38 мин',
                'likes' => 12,
            ],
            [
                'id' => $id * 100 + 2,
                'author' => 'proba_fan',
                'body' => 'ТМ 3.5 выглядит смелее рынка. Модель на 58–60 — для рейтинга норм.',
                'ago' => '1 ч',
                'likes' => 8,
            ],
            [
                'id' => $id * 100 + 3,
                'author' => 'КатяСтаты',
                'body' => 'Угловые ТБ интересны, если хозяева снова закроют фланг ранним прессингом.',
                'ago' => '2 ч',
                'likes' => 5,
            ],
            [
                'id' => $id * 100 + 4,
                'author' => 'matchiq_newbie',
                'body' => 'Первый выбор в рейтинге — П2 с низкой вероятностью. Поехали.',
                'ago' => '3 ч',
                'likes' => 3,
            ],
        ];

        if ($started) {
            array_unshift($comments, [
                'id' => $id * 100,
                'author' => 'live_watcher',
                'body' => 'Темп высокий, как в превью. Выбор уже закрыт — остаётся болеть за свой исход.',
                'ago' => '12 мин',
                'likes' => 6,
            ]);
        }

        return $comments;
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
                'form_home' => ['В', 'В', 'Н', 'В', 'П'],
                'form_away' => ['П', 'В', 'В', 'Н', 'В'],
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
                'form_home' => ['Н', 'П', 'В', 'В', 'Н'],
                'form_away' => ['В', 'Н', 'П', 'П', 'В'],
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
                'form_home' => ['В', 'Н', 'В', 'П', 'В'],
                'form_away' => ['Н', 'В', 'П', 'В', 'Н'],
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
                'form_home' => ['П', 'П', 'Н', 'В', 'П'],
                'form_away' => ['В', 'В', 'В', 'Н', 'П'],
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
                'form_home' => ['В', 'В', 'В', 'Н', 'В'],
                'form_away' => ['П', 'Н', 'В', 'П', 'Н'],
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
                'form_home' => ['Н', 'В', 'П', 'В', 'В'],
                'form_away' => ['В', 'П', 'Н', 'В', 'П'],
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
                'form_home' => ['П', 'В', 'Н', 'Н', 'В'],
                'form_away' => ['Н', 'Н', 'П', 'В', 'В'],
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
                'form_home' => ['В', 'В', 'П', 'В', 'Н'],
                'form_away' => ['П', 'П', 'В', 'Н', 'П'],
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
                'form_home' => ['Н', 'П', 'П', 'В', 'Н'],
                'form_away' => ['В', 'В', 'Н', 'В', 'В'],
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
                'form_home' => ['В', 'Н', 'В', 'В', 'П'],
                'form_away' => ['П', 'В', 'Н', 'П', 'В'],
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
                'form_home' => ['П', 'Н', 'В', 'Н', 'В'],
                'form_away' => ['В', 'П', 'В', 'Н', 'Н'],
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
                'form_home' => ['В', 'В', 'Н', 'В', 'В'],
                'form_away' => ['В', 'П', 'В', 'Н', 'В'],
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
                'form_home' => ['В', 'Н', 'В', 'П', 'В'],
                'form_away' => ['Н', 'В', 'В', 'В', 'П'],
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
                'form_home' => ['В', 'В', 'П', 'Н', 'В'],
                'form_away' => ['П', 'В', 'Н', 'П', 'В'],
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
