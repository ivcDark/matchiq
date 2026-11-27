<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\CommandMessage;
use App\Data\Ingestion\ResultMessage;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\MatchEventType;
use App\Enums\PlayerPosition;
use App\Enums\SourceProviderCode;
use App\Enums\StatsPeriod;
use App\Models\Coach;
use App\Models\ExternalId;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\IngestionRunError;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchPlayerStat;
use App\Models\MatchTeam;
use App\Models\MatchTeamStat;
use App\Models\Player;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Services\Ingestion\Messaging\CommandPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchDetailsIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function newRun(IngestionKind $kind): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);

        return IngestionRun::create([
            'source_provider_id' => $provider->id,
            'kind' => $kind,
            'status' => IngestionStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function message(IngestionRun $run, array $override): ResultMessage
    {
        return ResultMessage::fromArray($override + [
            'schema_version' => 1,
            'message_id' => 'm',
            'run_id' => $run->id,
            'job_type' => $run->kind->value,
            'provider' => 'sstats',
        ]);
    }

    /** Применяет batch + done; возвращает прогон после закрытия. */
    private function apply(IngestionKind $kind, array $entities, array $errors = []): IngestionRun
    {
        $run = $this->newRun($kind);
        $apply = app(ApplyIngestionResult::class);
        $apply->handle($this->message($run, ['kind' => 'batch', 'seq' => 1, 'entities' => $entities, 'errors' => $errors]));
        $apply->handle($this->message($run, ['kind' => 'done', 'batches_total' => 1, 'source' => ['requests' => 2, 'rate_limited' => 0]]));

        return $run->refresh();
    }

    /** Страна, лига, сезон, стадия, две команды и завершённый матч Mat00001 (Zenit — Spartak). */
    private function seedMatch(): void
    {
        $this->apply(IngestionKind::SyncCatalog, [
            ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
            ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'Premier League', 'type' => 'league', 'scope' => 'domestic']],
            ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2026-2027', 'is_current' => true]],
        ]);
        $this->apply(IngestionKind::SyncStructure, [
            ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Zenit', 'country_external_id' => '189']],
            ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Spartak Moscow', 'country_external_id' => '189']],
            ['entity_type' => 'team', 'external_id' => 'TeAm0003', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'CSKA Moscow', 'country_external_id' => '189']],
            ['entity_type' => 'stage', 'external_id' => 'StG00001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Main', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
        ]);
        $this->apply(IngestionKind::SyncFixtures, [
            $this->fixture('Mat00001', 'TeAm0001', 'TeAm0002', '2026-08-01T16:30:00Z', 'finished'),
            $this->fixture('Mat00002', 'TeAm0002', 'TeAm0003', '2026-08-08T16:30:00Z', 'finished'),
            $this->fixture('Mat00003', 'TeAm0003', 'TeAm0001', '2026-08-15T16:30:00Z', 'scheduled'),
        ]);
    }

    private function fixture(string $id, string $home, string $away, string $kickoff, string $status): array
    {
        return [
            'entity_type' => 'match',
            'external_id' => $id,
            'data' => [
                'competition_external_id' => 'AbCd1234', 'season_external_id' => 'SeAs2026', 'stage_external_id' => 'StG00001',
                'round_name' => 'Round 1', 'round_number' => 1,
                'home_team_external_id' => $home, 'away_team_external_id' => $away,
                'kickoff_at' => $kickoff, 'status' => $status, 'period' => null,
                'home_goals' => $status === 'finished' ? 2 : null, 'away_goals' => $status === 'finished' ? 1 : null,
                'home_goals_ht' => null, 'away_goals_ht' => null, 'home_goals_et' => null, 'away_goals_et' => null,
                'home_pens' => null, 'away_pens' => null,
            ],
        ];
    }

    private function player(string $id, string $name, string $team, array $extra = []): array
    {
        return [
            'entity_type' => 'player',
            'external_id' => $id,
            'data' => $extra + ['name' => $name, 'team_external_id' => $team, 'position' => 'MF', 'has_profile' => true],
        ];
    }

    /** Люди из деталей матча: страна профиля, игроки обеих команд и тренер хозяев. */
    private function people(): array
    {
        return [
            ['entity_type' => 'country', 'external_id' => '31', 'data' => ['code' => 'BR', 'name' => 'Brazil', 'kind' => 'country']],
            ['entity_type' => 'coach', 'external_id' => 'CoHome01', 'data' => ['name' => 'Sergey Semak', 'country_external_id' => '189', 'team_external_id' => 'TeAm0001', 'birth_date' => '1975-02-27', 'has_profile' => true]],
            $this->player('PlHome01', 'Kirill Glebov', 'TeAm0001', ['country_external_id' => '189', 'birth_date' => '2000-02-03']),
            $this->player('PlHome02', 'Henrique Carmo', 'TeAm0001', ['country_external_id' => '31', 'position' => 'FW']),
            $this->player('PlAway01', 'Chinonso Offor', 'TeAm0002', ['country_external_id' => '189', 'position' => 'FW']),
            ['entity_type' => 'player', 'external_id' => 'PlAway02', 'data' => ['name' => 'Gassama N.', 'team_external_id' => 'TeAm0002', 'position' => 'DF', 'has_profile' => false]],
        ];
    }

    private function details(array $override = []): array
    {
        return [
            'entity_type' => 'match_details',
            'external_id' => 'Mat00001',
            'data' => $override + [
                'teams' => [
                    ['side' => 'home', 'team_external_id' => 'TeAm0001', 'coach_external_id' => 'CoHome01', 'formation' => '4-1-4-1', 'rating' => 7.2],
                    ['side' => 'away', 'team_external_id' => 'TeAm0002', 'formation' => '5-4-1', 'rating' => 6.7],
                ],
                'lineup' => [
                    ['team_external_id' => 'TeAm0001', 'player_external_id' => 'PlHome01', 'is_starter' => true, 'is_captain' => true, 'shirt_number' => 17, 'position' => 'MF', 'line' => 3, 'sort_order' => 0, 'minute_in' => null, 'minute_out' => 76],
                    ['team_external_id' => 'TeAm0001', 'player_external_id' => 'PlHome02', 'is_starter' => false, 'is_captain' => false, 'shirt_number' => 37, 'position' => 'FW', 'line' => null, 'sort_order' => 0, 'minute_in' => 47, 'minute_out' => null],
                    ['team_external_id' => 'TeAm0002', 'player_external_id' => 'PlAway01', 'is_starter' => true, 'is_captain' => false, 'shirt_number' => 9, 'position' => 'FW', 'line' => 3, 'sort_order' => 0, 'minute_in' => null, 'minute_out' => null],
                ],
                'events' => [
                    ['type' => 'goal', 'team_external_id' => 'TeAm0002', 'player_external_id' => 'PlAway01', 'related_player_external_id' => 'PlAway02', 'minute' => 1, 'added_minute' => null, 'score_home' => 0, 'score_away' => 1, 'sort_order' => 0, 'details' => ['source_event_id' => 'ev1']],
                    ['type' => 'yellow', 'team_external_id' => 'TeAm0001', 'player_external_id' => 'PlHome01', 'minute' => 90, 'added_minute' => 2, 'score_home' => null, 'score_away' => null, 'sort_order' => 1, 'details' => ['reason' => 'Foul', 'reason_code' => 12]],
                    ['type' => 'yellow', 'team_external_id' => 'TeAm0002', 'minute' => 62, 'sort_order' => 2, 'details' => ['not_on_pitch' => true, 'person_name' => 'Coach A.']],
                ],
                'team_stats' => [
                    ['team_external_id' => 'TeAm0001', 'period' => 'full_time', 'xg' => 2.25, 'xga' => 1.52, 'shots' => 18, 'shots_on_target' => 5, 'corners' => 6, 'possession_pct' => 65, 'passes' => 548, 'pass_accuracy_pct' => 82.85, 'saves' => 1, 'extra' => ['big_chances' => 4]],
                    ['team_external_id' => 'TeAm0002', 'period' => 'full_time', 'xg' => 1.52, 'xga' => 2.25, 'shots' => 14, 'possession_pct' => 35],
                    ['team_external_id' => 'TeAm0001', 'period' => 'first_half', 'xg' => 1.1, 'xga' => 0.4],
                ],
                'player_stats' => [
                    ['team_external_id' => 'TeAm0001', 'player_external_id' => 'PlHome01', 'minutes' => 76, 'goals' => 1, 'assists' => null, 'penalties_scored' => 1, 'shots' => 3, 'shots_on_target' => 2, 'xg' => 0.75, 'xa' => 0.001935, 'rating' => 7.7, 'is_motm' => true, 'extra' => ['expected_goals_on_target' => 1.2]],
                    ['team_external_id' => 'TeAm0002', 'player_external_id' => 'PlAway01', 'minutes' => 90, 'yellow_cards' => 0, 'rating' => 6.4, 'is_motm' => false],
                ],
            ],
        ];
    }

    private function load(?array $details = null): IngestionRun
    {
        return $this->apply(IngestionKind::SyncMatchDetails, [...$this->people(), $details ?? $this->details()]);
    }

    private function match(string $externalId = 'Mat00001'): FootballMatch
    {
        return FootballMatch::query()->whereHas('externalIds', fn ($q) => $q->where('external_id', $externalId))->firstOrFail();
    }

    public function test_details_create_people_lineups_events_and_stats(): void
    {
        $this->seedMatch();
        $run = $this->load();

        $this->assertSame(IngestionStatus::Ok, $run->status, json_encode($run->stats));
        $by = $run->stats['by_entity'];
        $this->assertSame(1, $by['coach']['created']);
        $this->assertSame(4, $by['player']['created']);
        $this->assertSame(1, $by['match_details']['created']);
        $this->assertSame(2, $by['match_team']['created']);
        $this->assertSame(3, $by['match_lineup']['created']);
        $this->assertSame(3, $by['match_event']['created']);
        $this->assertSame(3, $by['match_team_stat']['created']);
        $this->assertSame(2, $by['match_player_stat']['created']);
        $this->assertSame(0, $run->stats['failed']);
        $this->assertSame(7, $run->stats['created'], 'общие счётчики — только сущности результата: страна, тренер, 4 игрока, детали');

        $this->assertSame(4, Player::count());
        $this->assertSame(1, Coach::count());
        $this->assertSame(5, ExternalId::whereIn('entity_type', ['player', 'coach'])->count());

        $glebov = Player::firstWhere('name', 'Kirill Glebov');
        $this->assertSame('RU', $glebov->country->code);
        $this->assertSame('2000-02-03', $glebov->birth_date->format('Y-m-d'));
        $this->assertSame(PlayerPosition::Midfielder, $glebov->position);
        $this->assertSame('BR', Player::firstWhere('name', 'Henrique Carmo')->country->code);
        $this->assertSame('RU', Player::firstWhere('name', 'Gassama N.')->country->code, 'без профиля страна берётся у команды матча');

        $match = $this->match();
        $home = MatchTeam::where('match_id', $match->id)->where('side', 'home')->firstOrFail();
        $this->assertSame('4-1-4-1', $home->formation);
        $this->assertSame('7.20', $home->rating);
        $this->assertSame('Sergey Semak', $home->coach->name);
        $this->assertNull(MatchTeam::where('match_id', $match->id)->where('side', 'away')->firstOrFail()->coach_id);

        $lineup = MatchLineup::where('match_id', $match->id)->where('player_id', $glebov->id)->firstOrFail();
        $this->assertTrue($lineup->is_starter);
        $this->assertTrue($lineup->is_captain);
        $this->assertSame(17, $lineup->shirt_number);
        $this->assertSame('3', $lineup->line);
        $this->assertSame(76, $lineup->minute_out);
        $sub = MatchLineup::where('match_id', $match->id)->where('is_starter', false)->firstOrFail();
        $this->assertSame(47, $sub->minute_in);
        $this->assertNull($sub->line);

        $goal = MatchEvent::where('match_id', $match->id)->where('sort_order', 0)->firstOrFail();
        $this->assertSame(MatchEventType::Goal, $goal->type);
        $this->assertSame('Chinonso Offor', $goal->player->name);
        $this->assertSame('Gassama N.', $goal->relatedPlayer->name);
        $this->assertSame([0, 1], [$goal->score_home, $goal->score_away]);
        $coachCard = MatchEvent::where('match_id', $match->id)->where('sort_order', 2)->firstOrFail();
        $this->assertNull($coachCard->player_id);
        $this->assertTrue($coachCard->details['not_on_pitch']);
        $this->assertSame(2, MatchEvent::where('match_id', $match->id)->where('sort_order', 1)->firstOrFail()->added_minute);

        $teamStat = MatchTeamStat::where('match_id', $match->id)->where('team_id', $match->home_team_id)->where('period', StatsPeriod::FullTime->value)->firstOrFail();
        $this->assertSame('2.25', $teamStat->xg);
        $this->assertSame('1.52', $teamStat->xga);
        $this->assertSame('82.85', $teamStat->pass_accuracy_pct);
        $this->assertSame(18, $teamStat->shots);
        $this->assertSame(['big_chances' => 4], $teamStat->extra);

        $stat = MatchPlayerStat::where('match_id', $match->id)->where('player_id', $glebov->id)->firstOrFail();
        $this->assertSame('0.75', $stat->xg);
        $this->assertSame('0.00', $stat->xa, 'xA хранится с точностью decimal(5,2)');
        $this->assertSame('7.70', $stat->rating);
        $this->assertTrue($stat->is_motm);
        $this->assertSame(0, $stat->assists, 'отсутствующие счётчики → 0');
        $this->assertSame(1, $stat->penalties_scored);
        $this->assertSame(['expected_goals_on_target' => 1.2], $stat->extra);
        $this->assertFalse(MatchPlayerStat::where('player_id', Player::firstWhere('name', 'Chinonso Offor')->id)->firstOrFail()->is_motm);
    }

    public function test_rerun_is_unchanged_and_does_not_duplicate(): void
    {
        $this->seedMatch();
        $this->load();
        $before = [Player::count(), MatchLineup::count(), MatchEvent::count(), MatchTeamStat::count(), MatchPlayerStat::count(), MatchTeam::count()];
        $updatedAt = MatchLineup::first()->updated_at;

        $this->travel(5)->minutes();
        $run = $this->load();

        $this->assertSame($before, [Player::count(), MatchLineup::count(), MatchEvent::count(), MatchTeamStat::count(), MatchPlayerStat::count(), MatchTeam::count()]);
        $by = $run->stats['by_entity'];
        $this->assertSame(1, $by['match_details']['unchanged']);
        $this->assertSame(3, $by['match_lineup']['unchanged']);
        $this->assertSame(3, $by['match_event']['unchanged']);
        $this->assertSame(3, $by['match_team_stat']['unchanged']);
        $this->assertSame(2, $by['match_player_stat']['unchanged']);
        $this->assertSame(4, $by['player']['unchanged']);
        $this->assertSame(0, $run->stats['created'] + $run->stats['updated'], json_encode($by));
        $this->assertTrue($updatedAt->equalTo(MatchLineup::first()->updated_at));
    }

    public function test_changed_sections_are_synced_and_empty_sections_delete_nothing(): void
    {
        $this->seedMatch();
        $this->load();
        $match = $this->match();

        $base = $this->details();
        $lineup = [$base['data']['lineup'][0]];
        $lineup[0]['minute_out'] = 80;
        $changed = $this->details([
            'teams' => [],
            'lineup' => $lineup,
            'events' => [],
            'team_stats' => [['team_external_id' => 'TeAm0001', 'period' => 'full_time', 'xg' => 2.5]],
            'player_stats' => [],
        ]);

        $run = $this->load($changed);

        $by = $run->stats['by_entity'];
        $this->assertSame(1, $by['match_details']['updated']);
        $this->assertSame(1, MatchLineup::where('match_id', $match->id)->count(), 'лишние строки составов удалены');
        $this->assertSame(80, MatchLineup::where('match_id', $match->id)->first()->minute_out);
        $this->assertSame(1, MatchTeamStat::where('match_id', $match->id)->count());
        $this->assertSame('2.50', MatchTeamStat::where('match_id', $match->id)->first()->xg);
        $this->assertSame(3, MatchEvent::where('match_id', $match->id)->count(), 'пустая секция событий ничего не удаляет');
        $this->assertSame(2, MatchTeam::where('match_id', $match->id)->count());
        $this->assertSame(2, MatchPlayerStat::where('match_id', $match->id)->count());
        $this->assertArrayNotHasKey('match_team', $by);
    }

    public function test_missing_player_reference_fails_the_whole_match_atomically(): void
    {
        $this->seedMatch();
        $details = $this->details();
        $details['data']['lineup'][] = ['team_external_id' => 'TeAm0002', 'player_external_id' => 'PlGhost01', 'is_starter' => true, 'sort_order' => 1];

        $run = $this->load($details);

        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(1, $run->stats['by_entity']['match_details']['failed']);
        $this->assertSame(0, MatchLineup::count());
        $this->assertSame(0, MatchEvent::count());
        $error = IngestionRunError::where('ingestion_run_id', $run->id)->firstOrFail();
        $this->assertSame('match_details', $error->entity_type);
        $this->assertSame('Mat00001', $error->external_id);
        $this->assertStringContainsString('PlGhost01', $error->message);
    }

    public function test_foreign_team_and_wrong_side_are_conflicts(): void
    {
        $this->seedMatch();

        $foreign = $this->details();
        $foreign['data']['team_stats'][0]['team_external_id'] = 'TeAm0003';
        $this->assertSame(1, $this->load($foreign)->stats['by_entity']['match_details']['failed']);

        $swapped = $this->details();
        $swapped['data']['teams'][0]['side'] = 'away';
        $swapped['data']['teams'][1]['side'] = 'home';
        $this->assertSame(1, $this->load($swapped)->stats['by_entity']['match_details']['failed']);

        $this->assertSame(0, MatchTeam::count());
    }

    public function test_unknown_match_and_bad_values_are_reported(): void
    {
        $this->seedMatch();

        $run = $this->apply(IngestionKind::SyncMatchDetails, [...$this->people(), ['entity_type' => 'match_details', 'external_id' => 'MatNope1', 'data' => ['events' => []]]]);
        $this->assertSame(1, $run->stats['by_entity']['match_details']['failed']);

        $badType = $this->details();
        $badType['data']['events'][0]['type'] = 'teleport';
        $this->assertSame(1, $this->load($badType)->stats['by_entity']['match_details']['failed']);

        $duplicate = $this->details();
        $duplicate['data']['events'][1]['sort_order'] = 0;
        $this->assertSame(1, $this->load($duplicate)->stats['by_entity']['match_details']['failed']);

        $overflow = $this->details();
        $overflow['data']['team_stats'][0]['xg'] = 12345.0;
        $this->assertSame(1, $this->load($overflow)->stats['by_entity']['match_details']['failed']);

        $this->assertSame(0, MatchEvent::count());
    }

    public function test_player_without_profile_does_not_overwrite_known_fields(): void
    {
        $this->seedMatch();
        $this->load();

        $sparse = $this->player('PlHome01', 'Glebov K.', 'TeAm0001', ['has_profile' => false, 'position' => 'FW']);
        $run = $this->apply(IngestionKind::SyncMatchDetails, [$sparse]);

        $player = Player::firstWhere('name', 'Kirill Glebov');
        $this->assertNotNull($player);
        $this->assertSame('RU', $player->country->code);
        $this->assertSame('2000-02-03', $player->birth_date->format('Y-m-d'));
        $this->assertSame(PlayerPosition::Forward, $player->position, 'без профиля позиция берётся из состава, остальное не затирается');
        $this->assertSame(1, $run->stats['by_entity']['player']['updated']);
        $this->assertSame(4, Player::count());
    }

    private function fakePublisher(): object
    {
        $publisher = new class implements CommandPublisher
        {
            public array $sent = [];

            public function publish(CommandMessage $command): void
            {
                $this->sent[] = $command->toArray();
            }
        };
        $this->app->instance(CommandPublisher::class, $publisher);

        return $publisher;
    }

    public function test_dispatch_selects_finished_matches_by_season_filters(): void
    {
        $this->seedMatch();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league'])->assertSuccessful();

        $sent = $publisher->sent[0];
        $this->assertSame('sync_match_details', $sent['job_type']);
        $this->assertSame(['Mat00002', 'Mat00001'], $sent['payload']['match_external_ids'], 'только завершённые, свежие первыми');
        $this->assertSame('SeAs2026', $sent['payload']['season_external_id']);
        $this->assertSame('AbCd1234', $sent['payload']['competition']['external_id']);

        $run = IngestionRun::latest('id')->first();
        $this->assertSame(IngestionKind::SyncMatchDetails, $run->kind);
        $this->assertNotNull($run->competition_id);
    }

    public function test_dispatch_limit_window_stage_and_missing(): void
    {
        $this->seedMatch();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--limit' => '1'])->assertSuccessful();
        $this->assertSame(['Mat00002'], $publisher->sent[0]['payload']['match_external_ids']);
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);

        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--from' => '2026-08-01', '--to' => '2026-08-05', '--stage' => 'main'])->assertSuccessful();
        $this->assertSame(['Mat00001'], $publisher->sent[1]['payload']['match_external_ids']);
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);

        $this->load();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--missing' => true])->assertSuccessful();
        $this->assertSame(['Mat00002'], $publisher->sent[2]['payload']['match_external_ids'], 'Mat00001 уже загружен');
    }

    public function test_dispatch_by_match_external_id(): void
    {
        $this->seedMatch();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--match' => ['Mat00001']])->assertSuccessful();

        $payload = $publisher->sent[0]['payload'];
        $this->assertSame(['Mat00001'], $payload['match_external_ids']);
        $this->assertSame('AbCd1234', $payload['competition']['external_id']);
        $this->assertSame('SeAs2026', $payload['season_external_id']);
    }

    public function test_dispatch_rejects_bad_input(): void
    {
        $this->seedMatch();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--limit' => '0'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--limit' => '501'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--limit' => 'abc'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--stage' => 'Nope'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--from' => '2026-09-01', '--to' => '2026-08-01'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--match' => ['MatNope1']])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--from' => '2027-01-01'])->assertFailed();

        $this->assertSame([], $publisher->sent);
        $this->assertSame(0, IngestionRun::where('kind', 'sync_match_details')->count());
    }
}
