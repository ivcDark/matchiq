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
                'form_home' => ['W', 'D', 'L', 'W', 'D'],
                'form_away' => ['L', 'W', 'W', 'D', 'L'],
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
            'events' => $detail['events'],
            'comparison' => $detail['comparison'],
            'homeAway' => $detail['homeAway'],
            'standings' => $detail['standings'],
            'scorers' => $detail['scorers'],
            'absences' => $detail['absences'],
            'goalMinutes' => $detail['goalMinutes'],
            'h2h' => $detail['h2h'],
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
        ]);

        $events = $this->demoEventsForMatch($id, $status, $match);

        return [
            'match' => $enriched,
            'preview' => $this->demoPreview($home, $away, $status),
            'events' => $events,
            'comparison' => $this->demoComparison($home, $away, $id),
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
            'h2h' => $this->demoH2h($home, $away, $id),
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

    private function demoPreview(string $home, string $away, string $status): string
    {
        if ($status === 'finished') {
            return "Матч {$home} — {$away} уже сыгран. Ниже — снимок вероятностей на kickoff и то, как разошлись выборы сообщества. Сверка pick с итогом — в блоке событий.";
        }

        if ($status === 'live') {
            return "Игра идёт: вероятности зафиксированы на старте. По ходу встречи {$home} и {$away} держат темп выше среднего — следите за счётом в шапке, выбор в рейтинг уже закрыт.";
        }

        return "{$home} дома обычно контролирует мяч и создаёт момент через фланги, {$away} отвечает быстрыми переходами и стандартами. Модель ждёт открытый матч с голами у обеих сторон и умеренным числом угловых. Карточки вероятнее во второй половине — прессинг гостей устаёт к 70-й.";
    }

    /**
     * @param  array<string, mixed>  $match
     * @return list<array<string, mixed>>
     */
    private function demoEventsForMatch(int $id, string $status, array $match): array
    {
        $seed = abs($id * 17 + 3);
        $base = [
            ['id' => $id * 10 + 1, 'label' => 'Обе забьют — Да', 'prob' => 68, 'popular_pct' => 41],
            ['id' => $id * 10 + 2, 'label' => 'ТБ 2.5', 'prob' => 61, 'popular_pct' => 28],
            ['id' => $id * 10 + 3, 'label' => 'ТМ 3.5', 'prob' => 74, 'popular_pct' => 19],
            ['id' => $id * 10 + 4, 'label' => 'П1', 'prob' => (int) ($match['prob_home'] ?? 42), 'popular_pct' => 22],
            ['id' => $id * 10 + 5, 'label' => 'X', 'prob' => (int) ($match['prob_draw'] ?? 26), 'popular_pct' => 11],
            ['id' => $id * 10 + 6, 'label' => 'П2', 'prob' => (int) ($match['prob_away'] ?? 32), 'popular_pct' => 18],
            ['id' => $id * 10 + 7, 'label' => 'Угловые ТБ 9.5', 'prob' => 57, 'popular_pct' => 14],
            ['id' => $id * 10 + 8, 'label' => 'Жёлтые ТБ 4.5', 'prob' => 53, 'popular_pct' => 9],
            ['id' => $id * 10 + 9, 'label' => 'Тотал голов чет', 'prob' => 48, 'popular_pct' => 7],
            ['id' => $id * 10 + 10, 'label' => 'Хозяева забьют', 'prob' => 79, 'popular_pct' => 16],
            ['id' => $id * 10 + 11, 'label' => 'Гости забьют', 'prob' => 71, 'popular_pct' => 12],
            ['id' => $id * 10 + 12, 'label' => 'ТМ 1.5', 'prob' => 22, 'popular_pct' => 5],
        ];

        // Лёгкий разброс по id, чтобы матчи не выглядели копией.
        foreach ($base as $i => &$event) {
            $nudge = (($seed + $i * 13) % 9) - 4;
            $event['prob'] = max(8, min(92, $event['prob'] + $nudge));
            $event['popular_pct'] = max(3, min(55, $event['popular_pct'] + (($seed + $i) % 5) - 2));
            $event['hit'] = null;
        }
        unset($event);

        if ($status === 'finished') {
            foreach ($base as &$event) {
                $event['hit'] = $event['prob'] >= 50
                    ? (($seed + $event['id']) % 3 !== 0)
                    : (($seed + $event['id']) % 4 === 0);
            }
            unset($event);
        }

        // Фирменный матч Зенит — Спартак: чуть более «продающие» цифры.
        if ($id === 101) {
            $map = [
                'Обе забьют — Да' => [72, 44],
                'ТБ 2.5' => [64, 31],
                'ТМ 3.5' => [58, 17],
                'П1' => [42, 24],
                'X' => [27, 12],
                'П2' => [31, 19],
                'Угловые ТБ 9.5' => [61, 15],
                'Жёлтые ТБ 4.5' => [55, 10],
            ];
            foreach ($base as &$event) {
                if (isset($map[$event['label']])) {
                    [$p, $pop] = $map[$event['label']];
                    $event['prob'] = $p;
                    $event['popular_pct'] = $pop;
                }
            }
            unset($event);
        }

        return $base;
    }

    /**
     * @return list<array{key: string, label: string, home: float|int, away: float|int, unit?: string}>
     */
    private function demoComparison(string $home, string $away, int $id): array
    {
        $n = abs($id % 7);

        return [
            ['key' => 'goals', 'label' => 'Голы', 'home' => 1.8 + ($n % 3) * 0.2, 'away' => 1.4 + ($n % 2) * 0.3],
            ['key' => 'xg', 'label' => 'xG', 'home' => 1.65 + ($n % 4) * 0.1, 'away' => 1.35 + ($n % 3) * 0.1],
            ['key' => 'shots', 'label' => 'Удары', 'home' => 14 + $n, 'away' => 11 + ($n % 5)],
            ['key' => 'sot', 'label' => 'В створ', 'home' => 5.2 + ($n % 3) * 0.4, 'away' => 4.1 + ($n % 2) * 0.5],
            ['key' => 'corners', 'label' => 'Угловые', 'home' => 5.4 + ($n % 3) * 0.3, 'away' => 4.6 + ($n % 4) * 0.2],
            ['key' => 'yellows', 'label' => 'Жёлтые', 'home' => 1.8 + ($n % 2) * 0.2, 'away' => 2.2 + ($n % 3) * 0.2],
            ['key' => 'fouls', 'label' => 'Фолы', 'home' => 11 + ($n % 4), 'away' => 13 + ($n % 3)],
            ['key' => 'possession', 'label' => 'Владение %', 'home' => 54 + ($n % 5), 'away' => 46 - ($n % 5), 'unit' => '%'],
            ['key' => 'btts', 'label' => 'BTTS %', 'home' => 60 + ($n % 4) * 5, 'away' => 55 + ($n % 3) * 5, 'unit' => '%'],
            ['key' => 'cs', 'label' => 'Сухие %', 'home' => 35 + ($n % 3) * 5, 'away' => 25 + ($n % 4) * 5, 'unit' => '%'],
        ];
    }

    /**
     * Home/away split averages — competitor-inspired extra.
     *
     * @return array{home: array<string, mixed>, away: array<string, mixed>}
     */
    private function demoHomeAwaySplits(string $home, string $away, int $id): array
    {
        $n = abs($id % 5);

        return [
            'home' => [
                'team' => $home,
                'context' => 'дома',
                'gf' => 1.9 + $n * 0.1,
                'ga' => 0.9 + ($n % 3) * 0.1,
                'xg' => 1.7 + $n * 0.05,
                'corners' => 5.6 + ($n % 3) * 0.2,
            ],
            'away' => [
                'team' => $away,
                'context' => 'в гостях',
                'gf' => 1.3 + ($n % 3) * 0.1,
                'ga' => 1.4 + ($n % 2) * 0.2,
                'xg' => 1.2 + ($n % 4) * 0.08,
                'corners' => 4.2 + ($n % 3) * 0.3,
            ],
        ];
    }

    /**
     * @return list<array{pos: int, team: string, played: int, gd: int, pts: int, highlight: bool}>
     */
    private function demoMiniStandings(string $home, string $away, string $leagueId): array
    {
        if ($leagueId === 'ucl') {
            $rows = [
                ['pos' => 1, 'team' => 'Bayern', 'played' => 4, 'gd' => 7, 'pts' => 10],
                ['pos' => 2, 'team' => 'Real Madrid', 'played' => 4, 'gd' => 5, 'pts' => 9],
                ['pos' => 3, 'team' => 'Man City', 'played' => 4, 'gd' => 4, 'pts' => 8],
                ['pos' => 4, 'team' => 'Inter', 'played' => 4, 'gd' => 3, 'pts' => 7],
                ['pos' => 5, 'team' => 'Arsenal', 'played' => 4, 'gd' => 2, 'pts' => 7],
                ['pos' => 6, 'team' => 'PSG', 'played' => 4, 'gd' => 1, 'pts' => 6],
            ];
        } elseif ($leagueId === 'fnl') {
            $rows = [
                ['pos' => 1, 'team' => 'Балтика', 'played' => 16, 'gd' => 12, 'pts' => 32],
                ['pos' => 2, 'team' => 'Урал', 'played' => 16, 'gd' => 8, 'pts' => 29],
                ['pos' => 3, 'team' => 'Черноморец', 'played' => 16, 'gd' => 5, 'pts' => 27],
                ['pos' => 4, 'team' => 'Ротор', 'played' => 16, 'gd' => 2, 'pts' => 24],
                ['pos' => 5, 'team' => 'СКА-Хабаровск', 'played' => 16, 'gd' => 0, 'pts' => 22],
                ['pos' => 6, 'team' => 'Нефтехимик', 'played' => 16, 'gd' => -1, 'pts' => 20],
            ];
        } else {
            $rows = [
                ['pos' => 1, 'team' => 'Зенит', 'played' => 14, 'gd' => 18, 'pts' => 34],
                ['pos' => 2, 'team' => 'Краснодар', 'played' => 14, 'gd' => 14, 'pts' => 31],
                ['pos' => 3, 'team' => 'Спартак', 'played' => 14, 'gd' => 10, 'pts' => 28],
                ['pos' => 4, 'team' => 'ЦСКА', 'played' => 14, 'gd' => 8, 'pts' => 26],
                ['pos' => 5, 'team' => 'Динамо', 'played' => 14, 'gd' => 5, 'pts' => 24],
                ['pos' => 6, 'team' => 'Локомотив', 'played' => 14, 'gd' => 3, 'pts' => 22],
                ['pos' => 7, 'team' => 'Ростов', 'played' => 14, 'gd' => 1, 'pts' => 20],
                ['pos' => 8, 'team' => 'Рубин', 'played' => 14, 'gd' => -2, 'pts' => 18],
            ];
        }

        // Гарантируем, что обе команды видны: если нет — подмешиваем рядом.
        $names = collect($rows)->pluck('team');
        if (! $names->contains($home)) {
            $rows[] = ['pos' => count($rows) + 1, 'team' => $home, 'played' => 14, 'gd' => 0, 'pts' => 16];
        }
        if (! $names->contains($away) && $away !== $home) {
            $rows[] = ['pos' => count($rows) + 1, 'team' => $away, 'played' => 14, 'gd' => -1, 'pts' => 14];
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

        return array_map(static function (array $row) use ($home, $away) {
            $row['highlight'] = $row['team'] === $home || $row['team'] === $away;

            return $row;
        }, $slice);
    }

    /**
     * @return list<array{player: string, goals: int, assists?: int}>
     */
    private function demoTeamScorers(string $team, int $id, string $side): array
    {
        $catalog = [
            'Зенит' => [
                ['player' => 'М. Глушенков', 'goals' => 11, 'assists' => 4],
                ['player' => 'М. Кассьерра', 'goals' => 8, 'assists' => 2],
                ['player' => 'Вендел', 'goals' => 5, 'assists' => 6],
            ],
            'Спартак' => [
                ['player' => 'Т. Барко', 'goals' => 8, 'assists' => 5],
                ['player' => 'Э. Промес', 'goals' => 6, 'assists' => 3],
                ['player' => 'М. Угальде', 'goals' => 5, 'assists' => 2],
            ],
            'ЦСКА' => [
                ['player' => 'И. Обляков', 'goals' => 7, 'assists' => 4],
                ['player' => 'В. Давила', 'goals' => 5, 'assists' => 2],
                ['player' => 'Т. Мусаев', 'goals' => 4, 'assists' => 1],
            ],
            'Динамо' => [
                ['player' => 'К. Тюкавин', 'goals' => 7, 'assists' => 3],
                ['player' => 'Б. Захарян', 'goals' => 4, 'assists' => 5],
                ['player' => 'Я. Гладышев', 'goals' => 4, 'assists' => 2],
            ],
            'Real Madrid' => [
                ['player' => 'K. Mbappé', 'goals' => 9, 'assists' => 3],
                ['player' => 'V. Júnior', 'goals' => 6, 'assists' => 5],
                ['player' => 'J. Bellingham', 'goals' => 4, 'assists' => 4],
            ],
            'Man City' => [
                ['player' => 'E. Haaland', 'goals' => 11, 'assists' => 2],
                ['player' => 'P. Foden', 'goals' => 5, 'assists' => 4],
                ['player' => 'J. Álvarez', 'goals' => 4, 'assists' => 3],
            ],
        ];

        if (isset($catalog[$team])) {
            return $catalog[$team];
        }

        $base = 4 + ($id % 5);

        return [
            ['player' => $side === 'home' ? "Форвард {$team}" : "Нападающий {$team}", 'goals' => $base, 'assists' => 2],
            ['player' => "Полузащитник {$team}", 'goals' => max(2, $base - 2), 'assists' => 3],
            ['player' => "Вингер {$team}", 'goals' => max(1, $base - 3), 'assists' => 1],
        ];
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
    private function demoGoalMinutes(int $id): array
    {
        $n = abs($id % 4) * 0.05;

        return [
            ['band' => '0–15', 'home' => 0.22 + $n, 'away' => 0.18],
            ['band' => '15–30', 'home' => 0.28, 'away' => 0.24 + $n],
            ['band' => '30–45', 'home' => 0.35 + $n, 'away' => 0.30],
            ['band' => '45–60', 'home' => 0.31, 'away' => 0.33 + $n],
            ['band' => '60–75', 'home' => 0.40 + $n, 'away' => 0.29],
            ['band' => '75–90', 'home' => 0.45, 'away' => 0.38 + $n],
        ];
    }

    /**
     * @return array{summary: string, meetings: list<array<string, mixed>>}
     */
    private function demoH2h(string $home, string $away, int $id): array
    {
        $meetings = [
            [
                'date' => now()->subMonths(5)->toDateString(),
                'home' => $away,
                'away' => $home,
                'score_home' => 1,
                'score_away' => 2,
                'competition' => 'РПЛ',
            ],
            [
                'date' => now()->subMonths(11)->toDateString(),
                'home' => $home,
                'away' => $away,
                'score_home' => 2,
                'score_away' => 2,
                'competition' => 'РПЛ',
            ],
            [
                'date' => now()->subMonths(17)->toDateString(),
                'home' => $away,
                'away' => $home,
                'score_home' => 0,
                'score_away' => 1,
                'competition' => 'Кубок',
            ],
            [
                'date' => now()->subMonths(23)->toDateString(),
                'home' => $home,
                'away' => $away,
                'score_home' => 3,
                'score_away' => 1,
                'competition' => 'РПЛ',
            ],
        ];

        if ($id === 201) {
            $meetings = [
                [
                    'date' => now()->subMonths(3)->toDateString(),
                    'home' => 'Man City',
                    'away' => 'Real Madrid',
                    'score_home' => 2,
                    'score_away' => 3,
                    'competition' => 'UCL',
                ],
                [
                    'date' => now()->subMonths(8)->toDateString(),
                    'home' => 'Real Madrid',
                    'away' => 'Man City',
                    'score_home' => 1,
                    'score_away' => 1,
                    'competition' => 'UCL',
                ],
                [
                    'date' => now()->subYears(1)->toDateString(),
                    'home' => 'Man City',
                    'away' => 'Real Madrid',
                    'score_home' => 4,
                    'score_away' => 0,
                    'competition' => 'UCL',
                ],
            ];
        }

        return [
            'summary' => 'В последних встречах чаще забивали обе; домашний фактор заметен, но не решающий.',
            'meetings' => $meetings,
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
                'body' => 'Первый pick в рейтинге — П2 с низкой вероятностью. Поехали.',
                'ago' => '3 ч',
                'likes' => 3,
            ],
        ];

        if ($started) {
            array_unshift($comments, [
                'id' => $id * 100,
                'author' => 'live_watcher',
                'body' => 'Темп высокий, как в превью. Выбор уже закрыт — остаётся болеть за свой pick.',
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
