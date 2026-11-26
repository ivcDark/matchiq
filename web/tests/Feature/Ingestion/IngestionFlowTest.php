<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\CommandMessage;
use App\Data\Ingestion\ResultMessage;
use App\Enums\IngestionKind;
use App\Enums\IngestionPhase;
use App\Enums\IngestionStatus;
use App\Models\Competition;
use App\Models\Country;
use App\Models\ExternalId;
use App\Models\IngestionRun;
use App\Models\Season;
use App\Models\Stage;
use App\Models\Team;
use App\Repositories\SourceProviderRepository;
use App\Enums\SourceProviderCode;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Services\Ingestion\Exceptions\InvalidResultMessage;
use App\Services\Ingestion\Messaging\CommandPublisher;
use App\Services\Ingestion\StartIngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IngestionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function newRun(IngestionKind $kind = IngestionKind::SyncCatalog, ?int $competitionId = null): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);

        return IngestionRun::create([
            'source_provider_id' => $provider->id,
            'competition_id' => $competitionId,
            'kind' => $kind,
            'status' => IngestionStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function message(array $override): ResultMessage
    {
        return ResultMessage::fromArray($override + [
            'schema_version' => 1,
            'message_id' => 'm',
            'job_type' => 'sync_catalog',
            'provider' => 'sstats',
        ]);
    }

    private function catalogBatch(int $runId, int $seq = 1, array $errors = []): ResultMessage
    {
        return $this->message([
            'run_id' => $runId,
            'kind' => 'batch',
            'seq' => $seq,
            'entities' => [
                ['entity_type' => 'season', 'external_id' => 'SeAs2025', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2025-2026', 'is_current' => true]],
                ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'Premier League', 'type' => 'league', 'scope' => 'domestic']],
                ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
            ],
            'errors' => $errors,
        ]);
    }

    private function done(int $runId, int $batches): ResultMessage
    {
        return $this->message(['run_id' => $runId, 'kind' => 'done', 'batches_total' => $batches, 'source' => ['requests' => 3, 'rate_limited' => 0]]);
    }

    public function test_catalog_batch_creates_entities_and_closes_run(): void
    {
        $run = $this->newRun();
        $apply = app(ApplyIngestionResult::class);

        $apply->handle($this->catalogBatch($run->id));
        $apply->handle($this->done($run->id, 1));

        $country = Country::firstWhere('code', 'RU');
        $competition = Competition::firstWhere('name', 'Premier League');
        $season = Season::firstWhere('label', '2025-2026');

        $this->assertNotNull($country);
        $this->assertSame($country->id, $competition->country_id);
        $this->assertSame('premier-league', $competition->slug);
        $this->assertSame($competition->id, $season->competition_id);
        $this->assertTrue($season->is_current);
        $this->assertSame(3, ExternalId::where('last_ingestion_run_id', $run->id)->count());

        $run->refresh();
        $this->assertSame(IngestionStatus::Ok, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(3, $run->stats['created']);
        $this->assertSame(1, $run->stats['by_entity']['competition']['created']);
        $this->assertSame(0, $run->stats['failed']);
    }

    public function test_repeat_run_is_idempotent_and_reports_unchanged(): void
    {
        $apply = app(ApplyIngestionResult::class);
        $first = $this->newRun();
        $apply->handle($this->catalogBatch($first->id));
        $apply->handle($this->done($first->id, 1));

        $updatedAt = Competition::firstWhere('name', 'Premier League')->updated_at;
        $this->travel(5)->minutes();

        $second = $this->newRun();
        $apply->handle($this->catalogBatch($second->id));
        $apply->handle($this->done($second->id, 1));

        $second->refresh();
        $this->assertSame(0, $second->stats['created']);
        $this->assertSame(0, $second->stats['updated']);
        $this->assertSame(3, $second->stats['unchanged']);
        $this->assertSame(1, Country::count());
        $this->assertSame(1, Competition::count());
        $this->assertSame(3, ExternalId::count());
        $this->assertEquals($updatedAt, Competition::firstWhere('name', 'Premier League')->updated_at);
        $this->assertSame($second->id, ExternalId::firstWhere('external_id', 'AbCd1234')->last_ingestion_run_id);
    }

    public function test_changed_field_is_reported_as_updated(): void
    {
        $apply = app(ApplyIngestionResult::class);
        $first = $this->newRun();
        $apply->handle($this->catalogBatch($first->id));

        $second = $this->newRun();
        $apply->handle($this->message([
            'run_id' => $second->id, 'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russian Federation', 'kind' => 'country']],
            ],
        ]));

        $this->assertSame('Russian Federation', Country::firstWhere('code', 'RU')->name);
        $this->assertSame(1, $second->fresh()->stats['updated']);
    }

    public function test_errors_produce_partial_status_and_error_rows(): void
    {
        $run = $this->newRun();
        $apply = app(ApplyIngestionResult::class);

        $apply->handle($this->message([
            'run_id' => $run->id, 'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'season', 'external_id' => 'SeAsXXXX', 'data' => ['competition_external_id' => 'Unknown1', 'label' => '2024-2025']],
                ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia']],
            ],
            'errors' => [
                ['entity_type' => 'competition', 'external_id' => 'Bad00001', 'phase' => 'normalize', 'message' => 'у лиги нет названия'],
            ],
        ]));
        $apply->handle($this->done($run->id, 1));

        $run->refresh();
        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(2, $run->stats['failed']);
        $this->assertSame(1, $run->stats['created']);

        $errors = $run->errors()->orderBy('id')->get();
        $this->assertCount(2, $errors);
        $this->assertSame(IngestionPhase::Match, $errors[0]->phase);
        $this->assertSame('SeAsXXXX', $errors[0]->external_id);
        $this->assertSame(IngestionPhase::Normalize, $errors[1]->phase);
    }

    public function test_redelivered_batch_is_not_counted_twice(): void
    {
        $run = $this->newRun();
        $apply = app(ApplyIngestionResult::class);

        $apply->handle($this->catalogBatch($run->id, 1));
        $apply->handle($this->catalogBatch($run->id, 1));

        $this->assertSame(3, $run->fresh()->stats['created']);
    }

    public function test_run_waits_for_all_batches_before_finishing(): void
    {
        $run = $this->newRun();
        $apply = app(ApplyIngestionResult::class);

        $apply->handle($this->done($run->id, 2));
        $this->assertSame(IngestionStatus::Running, $run->fresh()->status);

        $apply->handle($this->catalogBatch($run->id, 1));
        $this->assertSame(IngestionStatus::Running, $run->fresh()->status);

        $apply->handle($this->message(['run_id' => $run->id, 'kind' => 'batch', 'seq' => 2, 'entities' => []]));
        $this->assertSame(IngestionStatus::Ok, $run->fresh()->status);
    }

    public function test_structure_creates_stages_and_teams_with_country_fallback(): void
    {
        $apply = app(ApplyIngestionResult::class);
        $catalog = $this->newRun();
        $apply->handle($this->catalogBatch($catalog->id));

        $run = $this->newRun(IngestionKind::SyncStructure);
        $apply->handle($this->message([
            'run_id' => $run->id, 'job_type' => 'sync_structure', 'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2025', 'name' => 'Zenit', 'country_external_id' => '189']],
                ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2025', 'name' => 'Spartak Moscow', 'short_name' => 'Spartak']],
                ['entity_type' => 'stage', 'external_id' => 'StG00001', 'data' => ['season_external_id' => 'SeAs2025', 'name' => 'Premier League', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
                ['entity_type' => 'stage', 'external_id' => 'StG00002', 'data' => ['season_external_id' => 'SeAs2025', 'name' => 'Cup', 'type' => 'knockout', 'stage_order' => 1, 'has_standings' => false]],
            ],
        ]));
        $apply->handle($this->message(['run_id' => $run->id, 'job_type' => 'sync_structure', 'kind' => 'done', 'batches_total' => 1]));

        $run->refresh();
        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(3, $run->stats['created']);
        $this->assertSame(1, $run->stats['failed']);

        $ru = Country::firstWhere('code', 'RU');
        $this->assertSame($ru->id, Team::firstWhere('name', 'Spartak Moscow')->country_id);
        $this->assertSame('zenit', Team::firstWhere('name', 'Zenit')->slug);
        $this->assertSame(1, Stage::count());
        $this->assertTrue(Stage::first()->has_standings);
        $this->assertStringContainsString('stage_order', $run->errors()->first()->message);
    }

    public function test_failed_message_closes_run_with_error(): void
    {
        $run = $this->newRun();
        app(ApplyIngestionResult::class)->handle($this->message([
            'run_id' => $run->id, 'kind' => 'failed', 'message' => 'sstats: invalid or missing apikey', 'retryable' => false,
        ]));

        $run->refresh();
        $this->assertSame(IngestionStatus::Failed, $run->status);
        $this->assertSame('sstats: invalid or missing apikey', $run->error);
    }

    public function test_message_for_closed_run_is_ignored(): void
    {
        $run = $this->newRun();
        $run->update(['status' => IngestionStatus::Failed, 'finished_at' => now(), 'error' => 'timeout']);

        app(ApplyIngestionResult::class)->handle($this->catalogBatch($run->id));

        $this->assertSame(0, Country::count());
    }

    public function test_invalid_messages_are_rejected(): void
    {
        $this->expectException(InvalidResultMessage::class);
        ResultMessage::fromArray(['schema_version' => 2, 'kind' => 'batch', 'run_id' => 1]);
    }

    public function test_start_run_publishes_command_and_blocks_duplicates(): void
    {
        $publisher = new class implements CommandPublisher
        {
            public array $sent = [];

            public bool $fail = false;

            public function publish(CommandMessage $command): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('broker down');
                }
                $this->sent[] = $command->toArray();
            }
        };
        $this->app->instance(CommandPublisher::class, $publisher);
        $start = app(StartIngestionRun::class);

        $run = $start->handle(IngestionKind::SyncCatalog, ['filters' => ['country' => 'Russia']]);

        $this->assertSame(IngestionStatus::Running, $run->status);
        $this->assertSame($run->id, $publisher->sent[0]['run_id']);
        $this->assertSame('sync_catalog', $publisher->sent[0]['job_type']);
        $this->assertSame('sstats', $publisher->sent[0]['provider']);
        $this->assertSame(1, $publisher->sent[0]['schema_version']);
        $this->assertSame('Russia', $publisher->sent[0]['payload']['filters']['country']);
        $this->assertStringContainsString('"context":{}', json_encode($publisher->sent[0]['payload']));

        try {
            $start->handle(IngestionKind::SyncCatalog, ['filters' => ['country' => 'Russia']]);
            $this->fail('duplicate run must be rejected');
        } catch (InvalidArgumentException) {
        }

        $this->travel(2)->hours();
        $publisher->fail = true;
        try {
            $start->handle(IngestionKind::SyncCatalog, ['filters' => ['country' => 'Russia']]);
            $this->fail('publish failure must bubble up');
        } catch (\RuntimeException) {
        }

        $this->assertSame('timeout', $run->fresh()->error);
        $failed = IngestionRun::latest('id')->first();
        $this->assertSame(IngestionStatus::Failed, $failed->status);
        $this->assertStringContainsString('broker down', $failed->error);
    }
}
