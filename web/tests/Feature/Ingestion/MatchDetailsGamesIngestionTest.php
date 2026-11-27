<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\ResultMessage;
use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\MatchEventType;
use App\Enums\MatchPeriod;
use App\Enums\MatchStatus;
use App\Enums\SourceProviderCode;
use App\Enums\SquadKind;
use App\Models\ExternalId;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\SquadMembership;
use App\Models\Team;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Детали матча из /Games/{id}: игроки с числовыми id SStats опознаются по имени, команды — по стороне. */
class MatchDetailsGamesIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function apply(IngestionKind $kind, array $entities): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);
        $run = IngestionRun::create([
            'source_provider_id' => $provider->id,
            'kind' => $kind,
            'status' => IngestionStatus::Running,
            'started_at' => now(),
        ]);
        $apply = app(ApplyIngestionResult::class);
        $base = ['schema_version' => 1, 'message_id' => 'm', 'run_id' => $run->id, 'job_type' => $kind->value, 'provider' => 'sstats'];
        $apply->handle(ResultMessage::fromArray($base + ['kind' => 'batch', 'seq' => 1, 'entities' => $entities, 'errors' => []]));
        $apply->handle(ResultMessage::fromArray($base + ['kind' => 'done', 'batches_total' => 1, 'source' => ['requests' => 1, 'rate_limited' => 0]]));

        return $run->refresh();
    }

    private function seedMatch(string $status = 'live', ?int $homeGoals = 0, ?int $awayGoals = 0): void
    {
        $this->apply(IngestionKind::SyncCatalog, [
            ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
            ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'First League', 'type' => 'league', 'scope' => 'domestic']],
            ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2026-2027', 'is_current' => true]],
        ]);
        $this->apply(IngestionKind::SyncStructure, [
            ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Torpedo', 'country_external_id' => '189']],
            ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'SKA', 'country_external_id' => '189']],
            ['entity_type' => 'stage', 'external_id' => 'StG00001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Main', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
        ]);
        $this->apply(IngestionKind::SyncFixtures, [[
            'entity_type' => 'match', 'external_id' => 'IPBoDE9D',
            'data' => [
                'competition_external_id' => 'AbCd1234', 'season_external_id' => 'SeAs2026', 'stage_external_id' => 'StG00001',
                'round_name' => 'Round 20', 'round_number' => 20, 'home_team_external_id' => 'TeAm0001', 'away_team_external_id' => 'TeAm0002',
                'kickoff_at' => '2026-11-27T16:30:00Z', 'status' => $status, 'period' => $status === 'live' ? 'first_half' : null,
                'home_goals' => $homeGoals, 'away_goals' => $awayGoals, 'home_goals_ht' => null, 'away_goals_ht' => null,
                'home_goals_et' => null, 'away_goals_et' => null, 'home_pens' => null, 'away_pens' => null,
            ],
        ]]);
    }

    /** @return list<array> */
    private function people(): array
    {
        return [
            ['entity_type' => 'player', 'external_id' => 'PlHome01', 'data' => ['name' => 'Kirill Glebov', 'team_external_id' => 'TeAm0001', 'position' => 'MF', 'has_profile' => true, 'country_external_id' => '189']],
            ['entity_type' => 'player', 'external_id' => 'PlHome02', 'data' => ['name' => 'Henrique Carmo', 'team_external_id' => 'TeAm0001', 'position' => 'FW', 'has_profile' => true, 'country_external_id' => '189']],
            ['entity_type' => 'player', 'external_id' => 'PlAway01', 'data' => ['name' => 'Andrey Mostovoy', 'team_external_id' => 'TeAm0002', 'position' => 'MF', 'has_profile' => true, 'country_external_id' => '189']],
        ];
    }

    private function games(array $override = []): array
    {
        return [
            'entity_type' => 'match_details',
            'external_id' => 'IPBoDE9D',
            'data' => $override + [
                'people_source' => 'sstats_api',
                'state' => ['status' => 'live', 'period' => 'first_half', 'minute' => 42, 'home_goals' => 1, 'away_goals' => 0, 'home_goals_ht' => null, 'away_goals_ht' => null],
                'teams' => [
                    ['side' => 'home', 'formation' => '4-4-2'],
                    ['side' => 'away'],
                ],
                'lineup' => [
                    ['side' => 'home', 'player_external_id' => '9001', 'player_name' => 'K. Glebov', 'is_starter' => true, 'is_captain' => false, 'shirt_number' => 10, 'position' => 'MF', 'line' => 1, 'sort_order' => 0],
                    ['side' => 'away', 'player_external_id' => '9003', 'player_name' => 'A. Mostovoy', 'is_starter' => true, 'is_captain' => false, 'shirt_number' => 8, 'position' => 'MF', 'line' => 1, 'sort_order' => 0],
                    ['side' => 'away', 'player_external_id' => '9999', 'player_name' => 'Unknown Guy', 'is_starter' => false, 'is_captain' => false, 'shirt_number' => 99, 'position' => 'FW', 'sort_order' => 1],
                ],
                'events' => [
                    ['type' => 'goal', 'side' => 'home', 'minute' => 12, 'score_home' => 1, 'score_away' => 0, 'sort_order' => 0, 'details' => ['source_event_id' => 'e1']],
                    ['type' => 'substitution', 'side' => 'home', 'player_external_id' => '9001', 'player_name' => 'Kirill Glebov', 'related_player_external_id' => '9002', 'related_player_name' => 'H. Carmo', 'minute' => 30, 'sort_order' => 1, 'details' => ['source_event_id' => 'e2']],
                    ['type' => 'yellow', 'side' => 'away', 'player_external_id' => '9777', 'player_name' => 'Nobody', 'minute' => 40, 'sort_order' => 2],
                ],
            ],
        ];
    }

    private function load(array $details): IngestionRun
    {
        $this->apply(IngestionKind::SyncMatchDetails, $this->people());
        $teams = ['Kirill Glebov' => 'Torpedo', 'Henrique Carmo' => 'Torpedo', 'Andrey Mostovoy' => 'SKA'];
        foreach ($teams as $player => $team) {
            SquadMembership::firstOrCreate([
                'player_id' => Player::where('name', $player)->value('id'),
                'team_id' => Team::where('name', $team)->value('id'),
            ], ['kind' => SquadKind::Permanent]);
        }

        return $this->apply(IngestionKind::SyncMatchDetails, [$details]);
    }

    private function match(): FootballMatch
    {
        return FootballMatch::query()->whereHas('externalIds', fn ($q) => $q->where('external_id', 'IPBoDE9D'))->firstOrFail();
    }

    public function test_games_details_resolve_players_by_name_teams_by_side_and_update_state(): void
    {
        $this->seedMatch();
        $run = $this->load($this->games());

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $match = $this->match();
        $this->assertSame(MatchStatus::Live, $match->status);
        $this->assertSame(MatchPeriod::FirstHalf, $match->period);
        $this->assertSame(42, $match->minute);
        $this->assertSame([1, 0], [$match->home_goals, $match->away_goals]);

        $glebov = Player::where('name', 'Kirill Glebov')->firstOrFail();
        $carmo = Player::where('name', 'Henrique Carmo')->firstOrFail();
        $this->assertSame(2, MatchLineup::where('match_id', $match->id)->count(), 'неопознанный участник состава пропущен');

        $goal = MatchEvent::where('match_id', $match->id)->where('type', MatchEventType::Goal)->firstOrFail();
        $this->assertNull($goal->player_id);
        $this->assertSame($match->home_team_id, $goal->team_id);

        $sub = MatchEvent::where('match_id', $match->id)->where('type', MatchEventType::Substitution)->firstOrFail();
        $this->assertSame($glebov->id, $sub->player_id);
        $this->assertSame($carmo->id, $sub->related_player_id);

        $yellow = MatchEvent::where('match_id', $match->id)->where('type', MatchEventType::Yellow)->firstOrFail();
        $this->assertNull($yellow->player_id);
        $this->assertSame('Nobody', $yellow->details['person_name']);

        $api = app(SourceProviderRepository::class)->ensure(SourceProviderCode::SstatsApi);
        $this->assertSame($glebov->id, ExternalId::where('source_provider_id', $api->id)
            ->where('entity_type', ExternalEntityType::Player->value)->where('external_id', '9001')->value('entity_id'));
    }

    public function test_known_api_id_resolves_without_name_and_rerun_is_unchanged(): void
    {
        $this->seedMatch();
        $this->load($this->games());

        $again = $this->games();
        $again['data']['events'][1]['player_name'] = '';
        $again['data']['events'][1]['related_player_name'] = '';
        $run = $this->load($again);

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $sub = MatchEvent::where('type', MatchEventType::Substitution)->firstOrFail();
        $this->assertNotNull($sub->player_id);
        $this->assertNotNull($sub->related_player_id);
    }

    public function test_finished_match_is_not_reopened_and_keeps_score(): void
    {
        $this->seedMatch('finished', 2, 1);
        $this->load($this->games(['state' => ['status' => 'live', 'period' => 'second_half', 'minute' => 50, 'home_goals' => 0, 'away_goals' => 0]]));

        $match = $this->match();
        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame([2, 1], [$match->home_goals, $match->away_goals]);
        $this->assertNull($match->minute);
    }

    public function test_live_match_becomes_finished_with_final_score(): void
    {
        $this->seedMatch('live', 1, 0);
        $this->load($this->games(['state' => ['status' => 'finished', 'home_goals' => 2, 'away_goals' => 1, 'home_goals_ht' => 1, 'away_goals_ht' => 0]]));

        $match = $this->match();
        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertNull($match->period);
        $this->assertSame([2, 1, 1, 0], [$match->home_goals, $match->away_goals, $match->home_goals_ht, $match->away_goals_ht]);
    }

    public function test_empty_live_details_are_not_a_failure(): void
    {
        $this->seedMatch();
        $run = $this->load(['entity_type' => 'match_details', 'external_id' => 'IPBoDE9D', 'data' => ['people_source' => 'sstats_api', 'state' => ['status' => 'live', 'period' => 'first_half', 'minute' => 5, 'home_goals' => 0, 'away_goals' => 0]]]);

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $this->assertSame(0, MatchEvent::count());
        $this->assertSame(5, $this->match()->minute);
    }

    public function test_unknown_people_source_fails_the_match(): void
    {
        $this->seedMatch();
        $run = $this->load($this->games(['people_source' => 'other']));

        $this->assertNotSame(IngestionStatus::Ok, $run->status);
        $this->assertSame(0, MatchEvent::count());
    }

    public function test_person_name_matching(): void
    {
        $pool = [1 => 'Andrey Mostovoy', 2 => 'Rodrigo Becão', 3 => 'Ivan Petrov', 4 => 'Igor Petrov'];

        $this->assertSame(1, PersonName::findUnique('A. Mostovoy', $pool));
        $this->assertSame(1, PersonName::findUnique('Mostovoy', $pool));
        $this->assertSame(2, PersonName::findUnique('Rodrigo Becao', $pool));
        $this->assertSame(2, PersonName::findUnique('R. Becão', $pool));
        $this->assertNull(PersonName::findUnique('Petrov', $pool), 'два однофамильца — не угадываем');
        $this->assertNull(PersonName::findUnique('I. Petrov', $pool), 'Ivan и Igor оба на «I.»');
        $this->assertSame(3, PersonName::findUnique('Ivan Petrov', $pool));
        $this->assertNull(PersonName::findUnique('Sidorov', $pool));
        $this->assertNull(PersonName::findUnique('A.', $pool));
    }
}
