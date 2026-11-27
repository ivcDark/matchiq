<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\CommandMessage;
use App\Data\Ingestion\ResultMessage;
use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\IngestionPhase;
use App\Enums\IngestionStatus;
use App\Enums\MatchPeriod;
use App\Enums\MatchStatus;
use App\Enums\RoundType;
use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Models\ExternalId;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\Round;
use App\Models\Stage;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Services\Ingestion\Messaging\CommandPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixturesIngestionTest extends TestCase
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

    /** Каталог + структура: страна, лига, сезон, стадия и три команды. */
    private function seedStructure(): void
    {
        $apply = app(ApplyIngestionResult::class);

        $catalog = $this->newRun(IngestionKind::SyncCatalog);
        $apply->handle($this->message($catalog, [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
                ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'Premier League', 'type' => 'league', 'scope' => 'domestic']],
                ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2026-2027', 'is_current' => true]],
                ['entity_type' => 'season', 'external_id' => 'SeAs2025', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2025-2026', 'is_current' => false]],
            ],
        ]));

        $structure = $this->newRun(IngestionKind::SyncStructure);
        $apply->handle($this->message($structure, [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Zenit', 'country_external_id' => '189']],
                ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Spartak Moscow', 'country_external_id' => '189']],
                ['entity_type' => 'team', 'external_id' => 'TeAm0003', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'CSKA Moscow', 'country_external_id' => '189']],
                ['entity_type' => 'stage', 'external_id' => 'StG00001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Main', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
                ['entity_type' => 'stage', 'external_id' => 'StG0OLD1', 'data' => ['season_external_id' => 'SeAs2025', 'name' => 'Main', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
            ],
        ]));
    }

    /** Каталог из /Leagues: числовой id лиги и uid сезона привязываются к уже известным по flash-id и label. */
    private function linkApiIds(): void
    {
        app(ApplyIngestionResult::class)->handle($this->message($this->newRun(IngestionKind::SyncCatalog), [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'competition', 'external_id' => '235', 'data' => ['api_id' => '235', 'flash_id' => 'AbCd1234', 'country_code' => 'RU', 'name' => 'Premier League']],
                ['entity_type' => 'season', 'external_id' => 'uid-2026', 'data' => ['api_id' => 'uid-2026', 'competition_api_id' => '235', 'label' => '2026-2027', 'is_current' => true]],
            ],
        ]));
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
    }

    private function match(string $id, array $data = []): array
    {
        return [
            'entity_type' => 'match',
            'external_id' => $id,
            'data' => $data + [
                'competition_external_id' => 'AbCd1234',
                'season_external_id' => 'SeAs2026',
                'stage_external_id' => 'StG00001',
                'round_name' => 'Round 1',
                'round_number' => 1,
                'home_team_external_id' => 'TeAm0001',
                'away_team_external_id' => 'TeAm0002',
                'kickoff_at' => '2026-07-24T14:00:00Z',
                'status' => 'scheduled',
                'period' => null,
                'home_goals' => null, 'away_goals' => null,
                'home_goals_ht' => null, 'away_goals_ht' => null,
                'home_goals_et' => null, 'away_goals_et' => null,
                'home_pens' => null, 'away_pens' => null,
            ],
        ];
    }

    private function fixturesBatch(IngestionRun $run, array $entities, array $errors = []): void
    {
        $apply = app(ApplyIngestionResult::class);
        $apply->handle($this->message($run, ['kind' => 'batch', 'seq' => 1, 'entities' => $entities, 'errors' => $errors]));
        $apply->handle($this->message($run, ['kind' => 'done', 'batches_total' => 1, 'source' => ['requests' => 1, 'rate_limited' => 0]]));
    }

    public function test_fixtures_create_matches_rounds_and_external_ids(): void
    {
        $this->seedStructure();
        $run = $this->newRun(IngestionKind::SyncFixtures);

        $this->fixturesBatch($run, [
            $this->match('Mat00001', [
                'status' => 'finished', 'home_goals' => 2, 'away_goals' => 1, 'home_goals_ht' => 1, 'away_goals_ht' => 0,
            ]),
            $this->match('Mat00002', [
                'home_team_external_id' => 'TeAm0003', 'away_team_external_id' => 'TeAm0001',
                'round_name' => 'Round 2', 'round_number' => 2, 'kickoff_at' => '2026-08-01T16:30:00Z',
                'status' => 'live', 'period' => 'second_half', 'home_goals' => 0, 'away_goals' => 0,
            ]),
            $this->match('Mat00003', ['round_name' => 'Final', 'round_number' => null, 'kickoff_at' => '2026-09-01T16:30:00Z']),
        ]);

        $run->refresh();
        $this->assertSame(IngestionStatus::Ok, $run->status);
        $this->assertSame(3, $run->stats['created']);
        $this->assertSame(3, $run->stats['by_entity']['match']['created']);
        $this->assertSame(3, FootballMatch::count());
        $this->assertSame(3, ExternalId::where('entity_type', 'match')->where('last_ingestion_run_id', $run->id)->count());

        $first = FootballMatch::query()->whereHas('externalIds', fn ($q) => $q->where('external_id', 'Mat00001'))->firstOrFail();
        $stage = Stage::firstWhere('name', 'Main');
        $this->assertSame(MatchStatus::Finished, $first->status);
        $this->assertSame(2, $first->home_goals);
        $this->assertSame(1, $first->home_goals_ht);
        $this->assertNull($first->home_pens);
        $this->assertSame('2026-07-24 14:00:00', $first->kickoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($stage->id, $first->stage_id);
        $this->assertSame($stage->season_id, $first->season_id);
        $this->assertSame($stage->season->competition_id, $first->competition_id);
        $this->assertSame('Zenit', $first->homeTeam->name);
        $this->assertSame(RoundType::Matchday, $first->round->type);
        $this->assertSame(1, $first->round->number);

        $live = FootballMatch::where('status', 'live')->firstOrFail();
        $this->assertSame(MatchPeriod::SecondHalf, $live->period);
        $this->assertSame('CSKA Moscow', $live->homeTeam->name);

        $this->assertSame(2, Round::count());
        $this->assertNull(FootballMatch::where('status', 'scheduled')->firstOrFail()->round_id);
    }

    public function test_repeat_run_is_idempotent_and_score_change_is_updated(): void
    {
        $this->seedStructure();
        $this->fixturesBatch($this->newRun(IngestionKind::SyncFixtures), [$this->match('Mat00001'), $this->match('Mat00002', ['away_team_external_id' => 'TeAm0003'])]);
        $updatedAt = FootballMatch::first()->updated_at;
        $this->travel(5)->minutes();

        $second = $this->newRun(IngestionKind::SyncFixtures);
        $this->fixturesBatch($second, [$this->match('Mat00001'), $this->match('Mat00002', ['away_team_external_id' => 'TeAm0003'])]);

        $second->refresh();
        $this->assertSame(0, $second->stats['created']);
        $this->assertSame(2, $second->stats['unchanged']);
        $this->assertSame(2, FootballMatch::count());
        $this->assertSame(2, ExternalId::where('entity_type', 'match')->count());
        $this->assertEquals($updatedAt, FootballMatch::orderBy('id')->first()->updated_at);

        $third = $this->newRun(IngestionKind::SyncFixtures);
        $this->fixturesBatch($third, [
            $this->match('Mat00001', ['status' => 'finished', 'home_goals' => 3, 'away_goals' => 0, 'kickoff_at' => '2026-07-24T15:00:00Z']),
        ]);

        $third->refresh();
        $this->assertSame(1, $third->stats['updated']);
        $match = FootballMatch::orderBy('id')->first();
        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame(3, $match->home_goals);
        $this->assertSame('15:00', $match->kickoff_at->utc()->format('H:i'));
        $this->assertSame(2, FootballMatch::count());
    }

    public function test_period_is_dropped_when_match_is_not_live(): void
    {
        $this->seedStructure();
        $this->fixturesBatch($this->newRun(IngestionKind::SyncFixtures), [$this->match('Mat00001', ['status' => 'live', 'period' => 'first_half', 'home_goals' => 0, 'away_goals' => 0])]);
        $this->fixturesBatch($this->newRun(IngestionKind::SyncFixtures), [$this->match('Mat00001', ['status' => 'finished', 'period' => 'second_half', 'home_goals' => 1, 'away_goals' => 0])]);

        $this->assertNull(FootballMatch::firstOrFail()->period);
    }

    public function test_invalid_matches_become_error_rows_without_breaking_the_run(): void
    {
        $this->seedStructure();
        $run = $this->newRun(IngestionKind::SyncFixtures);

        $this->fixturesBatch($run, [
            $this->match('Mat00001'),
            $this->match('MatNoTm1', ['home_team_external_id' => 'Unknown1']),
            $this->match('MatSame1', ['away_team_external_id' => 'TeAm0001']),
            $this->match('MatOther', ['stage_external_id' => 'StG0OLD1']),
            $this->match('MatBadSt', ['status' => 'weird']),
            $this->match('MatBadSc', ['status' => 'finished', 'home_goals' => -1]),
        ], [
            ['entity_type' => 'match', 'external_id' => 'MatGoErr', 'phase' => 'normalize', 'message' => 'неизвестный статус матча', 'context' => ['raw_status' => 777]],
        ]);

        $run->refresh();
        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(1, $run->stats['created']);
        $this->assertSame(6, $run->stats['failed']);
        $this->assertSame(1, FootballMatch::count());

        $errors = $run->errors()->orderBy('id')->get()->keyBy('external_id');
        $this->assertSame(IngestionPhase::Match, $errors['MatNoTm1']->phase);
        $this->assertStringContainsString('Unknown1', $errors['MatNoTm1']->message);
        $this->assertSame(IngestionPhase::Normalize, $errors['MatSame1']->phase);
        $this->assertSame(IngestionPhase::Match, $errors['MatOther']->phase);
        $this->assertSame(IngestionPhase::Normalize, $errors['MatBadSt']->phase);
        $this->assertSame(IngestionPhase::Normalize, $errors['MatBadSc']->phase);
        $this->assertSame(IngestionPhase::Normalize, $errors['MatGoErr']->phase);
        $this->assertSame('match', $errors['MatGoErr']->entity_type);
    }

    public function test_new_external_id_adopts_existing_unlinked_match_instead_of_duplicating(): void
    {
        $this->seedStructure();
        $this->fixturesBatch($this->newRun(IngestionKind::SyncFixtures), [$this->match('Mat00001')]);
        ExternalId::where('external_id', 'Mat00001')->delete();

        $run = $this->newRun(IngestionKind::SyncFixtures);
        $this->fixturesBatch($run, [$this->match('MatNewId', ['kickoff_at' => '2026-07-24T14:30:00Z'])]);

        $this->assertSame(1, FootballMatch::count());
        $this->assertSame(1, $run->fresh()->stats['updated']);
        $this->assertSame(FootballMatch::first()->id, ExternalId::firstWhere('external_id', 'MatNewId')->entity_id);
    }

    public function test_dispatch_builds_fixtures_payload_with_filters(): void
    {
        $this->seedStructure();
        $this->linkApiIds();
        $publisher = new class implements CommandPublisher
        {
            public array $sent = [];

            public function publish(CommandMessage $command): void
            {
                $this->sent[] = $command->toArray();
            }
        };
        $this->app->instance(CommandPublisher::class, $publisher);

        $this->artisan('ingest:dispatch', [
            'type' => 'sync_fixtures', '--competition' => 'premier-league', '--stage' => 'main', '--from' => '2026-08-01', '--to' => '2026-08-31',
        ])->assertSuccessful();

        $sent = $publisher->sent[0];
        $this->assertSame('sync_fixtures', $sent['job_type']);
        $this->assertSame('uid-2026', $sent['payload']['season_api_id']);
        $this->assertSame('SeAs2026', $sent['payload']['season_external_id']);
        $this->assertSame('StG00001', $sent['payload']['stage_external_id']);
        $this->assertArrayNotHasKey('ls_stages', $sent['payload']);
        $this->assertSame('AbCd1234', $sent['payload']['competition']['external_id']);
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], $sent['payload']['window']);

        $run = IngestionRun::latest('id')->first();
        $this->assertSame(IngestionKind::SyncFixtures, $run->kind);
        $this->assertNotNull($run->competition_id);
    }

    public function test_dispatch_without_filters_uses_current_season_and_all_stages(): void
    {
        $this->seedStructure();
        $this->linkApiIds();
        $publisher = new class implements CommandPublisher
        {
            public array $sent = [];

            public function publish(CommandMessage $command): void
            {
                $this->sent[] = $command->toArray();
            }
        };
        $this->app->instance(CommandPublisher::class, $publisher);

        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league'])->assertSuccessful();

        $payload = $publisher->sent[0]['payload'];
        $this->assertSame('uid-2026', $payload['season_api_id']);
        $this->assertSame('SeAs2026', $payload['season_external_id']);
        $this->assertArrayNotHasKey('stage_external_id', $payload);
        $this->assertArrayNotHasKey('window', $payload);
        $this->assertArrayNotHasKey('ls_stages', $payload, 'одна стадия — этап берётся из БД');

        app(ApplyIngestionResult::class)->handle($this->message($this->newRun(IngestionKind::SyncStructure), [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [['entity_type' => 'stage', 'external_id' => 'StG00002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Play-off', 'type' => 'knockout', 'stage_order' => 2, 'has_standings' => false]]],
        ]));
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league'])->assertSuccessful();
        $this->assertTrue($publisher->sent[1]['payload']['ls_stages'], 'многоэтапный сезон — этап и тур из /Ls/List');
    }

    public function test_dispatch_without_api_ids_falls_back_to_league_and_year_or_fails(): void
    {
        $this->seedStructure();
        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league'])->assertFailed();

        $competition = Competition::firstOrFail();
        app(ExternalIdRepository::class)->track(
            app(SourceProviderRepository::class)->ensure(SourceProviderCode::SstatsApi)->id, ExternalEntityType::Competition, '235', $competition->id, IngestionRun::firstOrFail()->id,
        );
        $publisher = new class implements CommandPublisher
        {
            public array $sent = [];

            public function publish(CommandMessage $command): void
            {
                $this->sent[] = $command->toArray();
            }
        };
        $this->app->instance(CommandPublisher::class, $publisher);
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);

        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league'])->assertSuccessful();
        $payload = $publisher->sent[0]['payload'];
        $this->assertSame('235', $payload['league_api_id']);
        $this->assertSame(2026, $payload['season_year']);
        $this->assertArrayNotHasKey('season_api_id', $payload);
    }

    public function test_dispatch_rejects_bad_input(): void
    {
        $this->seedStructure();

        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league', '--from' => '01.08.2026'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league', '--from' => '2026-09-01', '--to' => '2026-08-01'])->assertFailed();
        $this->artisan('ingest:dispatch', ['type' => 'sync_fixtures', '--competition' => 'premier-league', '--stage' => 'Nope'])->assertFailed();

        $this->assertSame(0, IngestionRun::where('kind', 'sync_fixtures')->count());
    }
}
