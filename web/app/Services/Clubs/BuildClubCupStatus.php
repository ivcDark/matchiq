<?php

namespace App\Services\Clubs;

use App\Domains\Matches\LeagueTable;
use App\Domains\Tournaments\FinishedMatch;
use App\Domains\Tournaments\RoundLabel;
use App\Enums\MatchStatus;
use App\Enums\StageType;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\Stage;
use App\Models\StageTeam;
use App\Models\Team;
use App\Repositories\TournamentCatalogRepository;
use Illuminate\Support\Collection;

/**
 * Статус клуба в нелиговом турнире сезона (кубок, турнир с группами и плей-офф) по этапам, группам и матчам БД.
 * Групповой этап — таблица группы и место клуба; плей-офф — текущая стадия и исход пары.
 */
class BuildClubCupStatus
{
    private const WIDE_TABLE = 8;

    private const NEIGHBORS = 5;

    public function __construct(private readonly TournamentCatalogRepository $catalog) {}

    /**
     * @param  Collection<int, FootballMatch>  $matches  матчи клуба в этом турнире (текущий сезон, без товарищеских)
     * @param  Collection<int, StageTeam>  $stageTeams  заявки клуба на этапы этого сезона
     * @param  array<string, mixed>|null  $next  ближайший матч клуба в турнире
     * @return array<string, mixed>
     */
    public function handle(Team $team, Competition $competition, Collection $matches, Collection $stageTeams, ?array $next, string $name, string $locale): array
    {
        $stages = $matches->map(fn (FootballMatch $m) => $m->stage)
            ->merge($stageTeams->map(fn ($st) => $st->stage))
            ->filter()->unique('id');
        $stage = $stages->sortByDesc('stage_order')->first();

        $block = [
            'key' => $competition->slug,
            'kind' => 'cup',
            'competition_id' => $competition->id,
            'name' => $name,
            'stage' => $stage === null ? '' : (string) $stage->name,
            'status' => 'registered',
            'status_label' => 'Заявлен, матчей пока нет',
            'group' => null,
            'tie' => null,
            'next' => $next,
        ];

        if ($stage === null) {
            return $matches->isEmpty() ? $block : ['status' => 'in_play', 'status_label' => 'Участвует'] + $block;
        }

        $inStage = $matches->where('stage_id', $stage->id)->values();

        if ($stage->type->hasStandings() || $stage->has_standings) {
            return $this->groupBlock($block, $team, $stage, $inStage, $stageTeams, $locale);
        }

        return $this->knockoutBlock($block, $team, $stage, $inStage, $locale);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, FootballMatch>  $inStage
     * @param  Collection<int, StageTeam>  $stageTeams
     * @return array<string, mixed>
     */
    private function groupBlock(array $block, Team $team, Stage $stage, Collection $inStage, Collection $stageTeams, string $locale): array
    {
        $groupId = $stageTeams->where('stage_id', $stage->id)->pluck('stage_group_id')->filter()->first()
            ?? $inStage->pluck('stage_group_id')->filter()->first();
        $groupName = $stageTeams->where('stage_group_id', $groupId)->first()?->group?->name
            ?? $inStage->where('stage_group_id', $groupId)->first()?->stageGroup?->name;

        $table = LeagueTable::compute($this->catalog->finishedInStage($stage->id, $groupId));
        $index = null;
        foreach ($table as $i => $row) {
            if ($row['team_id'] === $team->id) {
                $index = $i;
            }
        }

        $title = $groupId !== null && $groupName !== null ? $groupName : (string) $stage->name;
        $block['stage'] = $stage->type === StageType::Group ? 'Групповой этап' : (string) $stage->name;
        $block['status'] = 'group';
        $block['status_label'] = $stage->type === StageType::Group ? 'Групповой этап' : $title;

        if ($index === null) {
            $block['group'] = ['name' => $title, 'place' => null, 'teams' => count($table), 'zone_label' => null, 'table' => []];

            return $block;
        }

        $zones = $this->catalog->zonesForStage($stage->id);
        $zoneFor = fn (int $pos) => $zones->first(fn ($z) => $z->from_position <= $pos && $pos <= $z->to_position);
        $position = $table[$index]['pos'];

        $start = count($table) > self::WIDE_TABLE ? max(0, min($index - 2, count($table) - self::NEIGHBORS)) : 0;
        $window = count($table) > self::WIDE_TABLE ? array_slice($table, $start, self::NEIGHBORS) : $table;
        $names = $this->catalog->teamsByIds(array_column($window, 'team_id'));

        $block['group'] = [
            'name' => $title,
            'place' => $position,
            'teams' => count($table),
            'played' => $table[$index]['played'],
            'pts' => $table[$index]['pts'],
            'zone' => $zoneFor($position)?->code,
            'zone_label' => $zoneFor($position)?->label,
            'table' => array_map(fn (array $r) => [
                'pos' => $r['pos'],
                'team' => $names[$r['team_id']]->localized('name', $locale),
                'team_id' => $r['team_id'],
                'played' => $r['played'],
                'wins' => $r['wins'],
                'draws' => $r['draws'],
                'losses' => $r['losses'],
                'gd' => $r['gd'],
                'pts' => $r['pts'],
                'zone' => $zoneFor($r['pos'])?->code,
                'is_current' => $r['team_id'] === $team->id,
            ], $window),
        ];

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, FootballMatch>  $inStage
     * @return array<string, mixed>
     */
    private function knockoutBlock(array $block, Team $team, Stage $stage, Collection $inStage, string $locale): array
    {
        if ($inStage->isEmpty()) {
            $block['stage'] = (string) $stage->name;
            $block['status_label'] = 'Заявлен на '.mb_strtolower((string) $stage->name).', матчей пока нет';

            return $block;
        }

        $ties = $inStage->groupBy(fn (FootballMatch $m) => $m->tie_id !== null
            ? 't'.$m->tie_id
            : 'r'.$m->round_id.':'.($m->home_team_id === $team->id ? $m->away_team_id : $m->home_team_id));

        $current = $ties->sortByDesc(fn (Collection $legs) => [
            $legs->first()->round?->number ?? 0,
            $legs->max(fn (FootballMatch $m) => $m->kickoff_at->getTimestamp()),
        ])->first();

        $first = $current->first();
        $round = RoundLabel::label($first->round, null, $stage);
        $opponentId = $first->home_team_id === $team->id ? $first->away_team_id : $first->home_team_id;
        $opponent = $this->catalog->teamsByIds([$opponentId])[$opponentId] ?? null;

        $legs = $current->sortBy('kickoff_at')->values();
        $finished = $legs->filter(fn (FootballMatch $m) => FinishedMatch::is($m))->values();
        $expected = max(
            $first->tie?->legs ?? $first->round?->legs ?? $stage->default_legs ?? 1,
            $legs->count(),
        );
        $complete = $finished->count() >= $expected && $legs->every(fn (FootballMatch $m) => $m->status === MatchStatus::Finished);

        $gf = $finished->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->home_goals : $m->away_goals);
        $ga = $finished->sum(fn (FootballMatch $m) => $m->home_team_id === $team->id ? $m->away_goals : $m->home_goals);
        $last = $finished->last();
        $pensFor = $last === null ? null : ($last->home_team_id === $team->id ? $last->home_pens : $last->away_pens);
        $pensAgainst = $last === null ? null : ($last->home_team_id === $team->id ? $last->away_pens : $last->home_pens);

        $won = null;
        if ($first->tie?->winner_team_id !== null) {
            $won = $first->tie->winner_team_id === $team->id;
        } elseif ($complete) {
            $won = match (true) {
                $gf !== $ga => $gf > $ga,
                $pensFor !== null && $pensAgainst !== null && $pensFor !== $pensAgainst => $pensFor > $pensAgainst,
                default => null,
            };
        }

        $isFinal = $first->round?->code?->value === 'F';
        [$status, $label] = match (true) {
            $won === true && $isFinal => ['winner', 'Победитель'],
            $won === true => ['advanced', 'Прошёл дальше · '.$round],
            $won === false && $isFinal => ['eliminated', 'Финалист'],
            $won === false => ['eliminated', 'Выбыл · '.$round],
            default => ['in_play', 'В игре · '.$round],
        };

        $block['stage'] = $round;
        $block['status'] = $status;
        $block['status_label'] = $label;
        $block['tie'] = [
            'round' => $round,
            'opponent' => $opponent?->localized('name', $locale) ?? '',
            'opponent_id' => $opponentId,
            'aggregate' => $finished->isEmpty() ? null : $gf.':'.$ga,
            'penalties' => $pensFor !== null && $pensAgainst !== null ? $pensFor.':'.$pensAgainst : null,
            'result' => $won === null ? null : ($won ? 'won' : 'lost'),
        ];

        return $block;
    }
}
