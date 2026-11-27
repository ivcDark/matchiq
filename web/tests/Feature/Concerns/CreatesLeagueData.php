<?php

namespace Tests\Feature\Concerns;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\PlayerPosition;
use App\Enums\StageType;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Stage;
use App\Models\Team;

/** Мини-лига для feature-тестов страниц турнира и клуба: страна, лига, сезон, этап, команды и матчи. */
trait CreatesLeagueData
{
    protected Country $country;

    protected Competition $competition;

    protected Season $season;

    protected Stage $stage;

    /** @var array<string, Team> */
    protected array $teams = [];

    /** @var array<int, Round> */
    protected array $rounds = [];

    protected function createLeague(CompetitionStatus $status = CompetitionStatus::Live): void
    {
        $this->country = Country::create(['code' => 'RU', 'name' => 'Russia', 'name_i18n' => ['ru' => 'Россия']]);
        $this->competition = Competition::create([
            'country_id' => $this->country->id, 'slug' => 'premier-league', 'name' => 'Premier League',
            'name_i18n' => ['ru' => 'Премьер-лига'], 'short_name' => 'RPL', 'short_name_i18n' => ['ru' => 'РПЛ'],
            'type' => CompetitionType::League, 'status' => $status, 'sort_order' => 10,
        ]);
        $this->season = $this->createSeason($this->competition, '2026-2027', true);
        $this->stage = $this->createStage($this->season);

        foreach ([['zenit', 'Zenit', 'Зенит'], ['spartak', 'Spartak Moscow', 'Спартак'], ['cska', 'CSKA Moscow', 'ЦСКА'], ['dynamo', 'Dynamo Moscow', 'Динамо']] as [$slug, $name, $ru]) {
            $this->teams[$slug] = Team::create([
                'country_id' => $this->country->id, 'slug' => $slug, 'name' => $name, 'name_i18n' => ['ru' => $ru],
                'city' => 'Moscow', 'city_i18n' => ['ru' => 'Москва'],
            ]);
        }
    }

    protected function createSeason(Competition $competition, string $slug, bool $current): Season
    {
        return Season::create([
            'competition_id' => $competition->id, 'label' => $slug, 'slug' => $slug, 'is_current' => $current,
        ]);
    }

    protected function createStage(Season $season): Stage
    {
        return Stage::create([
            'season_id' => $season->id, 'name' => 'Main', 'type' => StageType::League, 'cycles' => 2, 'has_standings' => true,
        ]);
    }

    protected function round(int $number, ?Stage $stage = null): Round
    {
        $stage ??= $this->stage;

        return $this->rounds[$stage->id.':'.$number] ??= Round::create(['stage_id' => $stage->id, 'number' => $number, 'type' => 'matchday']);
    }

    /** @param  array{0: int, 1: int}|null  $score */
    protected function match(string $home, string $away, string $kickoff, ?array $score = null, int $round = 1, array $attrs = []): FootballMatch
    {
        $season = $attrs['season'] ?? $this->season;
        unset($attrs['season']);
        $stage = $season->is($this->season) ? $this->stage : $season->stages()->first();

        return FootballMatch::create($attrs + [
            'competition_id' => $season->competition_id,
            'season_id' => $season->id,
            'stage_id' => $stage->id,
            'round_id' => $this->round($round, $stage)->id,
            'home_team_id' => $this->teams[$home]->id,
            'away_team_id' => $this->teams[$away]->id,
            'kickoff_at' => $kickoff,
            'status' => $score === null ? MatchStatus::Scheduled : MatchStatus::Finished,
            'home_goals' => $score[0] ?? null,
            'away_goals' => $score[1] ?? null,
        ]);
    }

    protected function player(string $slug, string $name, ?PlayerPosition $position = PlayerPosition::Forward): Player
    {
        return Player::create(['country_id' => $this->country->id, 'slug' => $slug, 'name' => $name, 'position' => $position]);
    }

    protected function event(FootballMatch $match, string $team, MatchEventType $type, ?Player $player, ?Player $related = null, int $minute = 10): MatchEvent
    {
        return MatchEvent::create([
            'match_id' => $match->id, 'team_id' => $this->teams[$team]->id, 'player_id' => $player?->id,
            'related_player_id' => $related?->id, 'type' => $type, 'minute' => $minute, 'sort_order' => $minute,
        ]);
    }

    protected function starter(FootballMatch $match, string $team, Player $player): MatchLineup
    {
        return MatchLineup::create([
            'match_id' => $match->id, 'team_id' => $this->teams[$team]->id, 'player_id' => $player->id,
            'is_starter' => true, 'position' => $player->position?->value,
        ]);
    }

    /**
     * Три тура: Зенит 2:0 Спартак, ЦСКА 1:1 Динамо (тур 1); Спартак 0:1 ЦСКА, Динамо 3:2 Зенит (тур 2);
     * ближайший тур 3 — Зенит—ЦСКА и Динамо—Спартак.
     *
     * @return array<string, FootballMatch>
     */
    protected function playRounds(): array
    {
        return [
            'zenit-spartak' => $this->match('zenit', 'spartak', '2026-11-07 14:00:00', [2, 0], 1),
            'cska-dynamo' => $this->match('cska', 'dynamo', '2026-11-08 17:30:00', [1, 1], 1),
            'spartak-cska' => $this->match('spartak', 'cska', '2026-11-14 14:00:00', [0, 1], 2),
            'dynamo-zenit' => $this->match('dynamo', 'zenit', '2026-11-15 17:30:00', [3, 2], 2),
            'zenit-cska' => $this->match('zenit', 'cska', '2026-12-05 14:00:00', null, 3),
            'dynamo-spartak' => $this->match('dynamo', 'spartak', '2026-12-06 17:30:00', null, 3),
        ];
    }
}
