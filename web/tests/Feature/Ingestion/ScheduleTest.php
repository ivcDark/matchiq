<?php

namespace Tests\Feature\Ingestion;

use App\Console\IngestSchedule;
use App\Data\Ingestion\CommandMessage;
use App\Data\Ingestion\ResultMessage;
use App\Enums\CompetitionStatus;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\MatchStatus;
use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use App\Services\Ingestion\Messaging\CommandPublisher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Event> */
    private function registered(): array
    {
        $schedule = new Schedule;
        IngestSchedule::register($schedule);

        return $schedule->events();
    }

    /** @return array<string, string> команда => cron */
    private function crons(): array
    {
        $result = [];
        foreach ($this->registered() as $event) {
            $result[trim((string) preg_replace("/^.*?'artisan'\s+/", '', $event->command))] = $event->expression;
        }

        return $result;
    }

    public function test_default_schedule_registers_all_ingest_jobs(): void
    {
        $crons = $this->crons();

        $this->assertSame([
            "ingest:run-scheduled 'fixtures'" => '*/10 * * * *',
            "ingest:run-scheduled 'details_missing'" => '*/15 * * * *',
            "ingest:run-scheduled 'details_recent'" => '10 * * * *',
            "ingest:run-scheduled 'details_live'" => '5-59/15 * * * *',
            "ingest:run-scheduled 'fixtures_full'" => '30 3 * * *',
            "ingest:run-scheduled 'catalog'" => '0 4 * * *',
            "ingest:run-scheduled 'structure'" => '10 4 * * *',
            "ingest:run-scheduled 'squads'" => '20 4 * * *',
            'ingest:reap-stuck' => '*/10 * * * *',
        ], $crons);
    }

    public function test_all_events_run_in_moscow_timezone(): void
    {
        $this->assertSame('Europe/Moscow', config('app.timezone'));

        $events = $this->registered();
        $this->assertCount(9, $events);
        foreach ($events as $event) {
            $this->assertSame('Europe/Moscow', (string) $event->timezone, $event->command);
        }
    }

    public function test_intervals_come_from_config_and_zero_disables_a_job(): void
    {
        config([
            'ingest.schedule.fixtures.every_minutes' => 5,
            'ingest.schedule.details_missing.every_minutes' => 0,
            'ingest.schedule.details_recent.every_minutes' => 180,
            'ingest.schedule.catalog.daily_at' => '',
            'ingest.schedule.structure.daily_at' => '05:45',
            'ingest.schedule.reap_stuck.every_minutes' => 30,
        ]);

        $crons = $this->crons();

        $this->assertSame('*/5 * * * *', $crons["ingest:run-scheduled 'fixtures'"]);
        $this->assertSame('10 */3 * * *', $crons["ingest:run-scheduled 'details_recent'"]);
        $this->assertSame('45 5 * * *', $crons["ingest:run-scheduled 'structure'"]);
        $this->assertSame('*/30 * * * *', $crons['ingest:reap-stuck']);
        $this->assertArrayNotHasKey("ingest:run-scheduled 'details_missing'", $crons);
        $this->assertArrayNotHasKey("ingest:run-scheduled 'catalog'", $crons);
    }

    public function test_schedule_can_be_disabled(): void
    {
        config(['ingest.schedule.enabled' => false]);

        $this->assertSame([], $this->registered());
    }

    public function test_application_schedule_is_visible_in_schedule_list(): void
    {
        Artisan::call('schedule:list', ['--next' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('ingest:run-scheduled', $output);
        $this->assertStringContainsString('fixtures', $output);
        $this->assertStringContainsString('details_missing', $output);
        $this->assertStringContainsString('ingest:reap-stuck', $output);
    }

    private function newRun(IngestionKind $kind, \DateTimeInterface $startedAt, IngestionStatus $status = IngestionStatus::Running): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);

        return IngestionRun::create([
            'source_provider_id' => $provider->id,
            'kind' => $kind,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $status === IngestionStatus::Running ? null : $startedAt,
        ]);
    }

    public function test_reap_stuck_closes_only_old_running_runs(): void
    {
        $stuck = $this->newRun(IngestionKind::SyncFixtures, now()->subMinutes(90));
        $fresh = $this->newRun(IngestionKind::SyncStructure, now()->subMinutes(5));
        $done = $this->newRun(IngestionKind::SyncCatalog, now()->subMinutes(200), IngestionStatus::Ok);

        $this->artisan('ingest:reap-stuck')->assertSuccessful();

        $stuck->refresh();
        $this->assertSame(IngestionStatus::Failed, $stuck->status);
        $this->assertSame('timeout', $stuck->error);
        $this->assertNotNull($stuck->finished_at);
        $this->assertSame(IngestionStatus::Running, $fresh->fresh()->status);
        $this->assertSame(IngestionStatus::Ok, $done->fresh()->status);
        $this->assertNull($done->fresh()->error);
    }

    public function test_reap_stuck_honours_minutes_option_and_dry_run(): void
    {
        $run = $this->newRun(IngestionKind::SyncFixtures, now()->subMinutes(20));

        $this->artisan('ingest:reap-stuck')->assertSuccessful();
        $this->assertSame(IngestionStatus::Running, $run->fresh()->status, 'порог по умолчанию — 60 минут');

        $this->artisan('ingest:reap-stuck', ['--minutes' => 10, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(IngestionStatus::Running, $run->fresh()->status, 'dry-run ничего не меняет');

        $this->artisan('ingest:reap-stuck', ['--minutes' => 10])->assertSuccessful();
        $this->assertSame(IngestionStatus::Failed, $run->fresh()->status);

        $this->artisan('ingest:reap-stuck', ['--minutes' => 'abc'])->assertFailed();
    }

    public function test_reap_stuck_unblocks_the_next_scheduled_run(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent);

        IngestionRun::query()->update(['started_at' => now()->subMinutes(90)]);
        $this->artisan('ingest:reap-stuck')->assertSuccessful();
        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures'])->assertSuccessful();

        $this->assertCount(2, $publisher->sent);
        $this->assertSame(2, IngestionRun::where('kind', IngestionKind::SyncFixtures->value)->count());
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

    /** Лига со статусом live и ingest_enabled, сезон и одна пара команд; при `$finished` — ещё и завершённый матч без деталей. */
    private function seedCompetition(bool $finished = false): void
    {
        $apply = app(ApplyIngestionResult::class);

        $catalog = $this->newRun(IngestionKind::SyncCatalog, now());
        $apply->handle($this->message($catalog, [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
                ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'Premier League', 'type' => 'league', 'scope' => 'domestic']],
                ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2026-2027', 'is_current' => true]],
            ],
        ]));

        $structure = $this->newRun(IngestionKind::SyncStructure, now());
        $apply->handle($this->message($structure, [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [
                ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Zenit', 'country_external_id' => '189']],
                ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Spartak Moscow', 'country_external_id' => '189']],
                ['entity_type' => 'stage', 'external_id' => 'StG00001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Main', 'type' => 'league', 'stage_order' => 1, 'has_standings' => true]],
            ],
        ]));

        if ($finished) {
            $fixtures = $this->newRun(IngestionKind::SyncFixtures, now());
            $apply->handle($this->message($fixtures, [
                'kind' => 'batch', 'seq' => 1,
                'entities' => [[
                    'entity_type' => 'match', 'external_id' => 'Mat00001',
                    'data' => [
                        'competition_external_id' => 'AbCd1234', 'season_external_id' => 'SeAs2026', 'stage_external_id' => 'StG00001',
                        'round_name' => 'Round 1', 'round_number' => 1,
                        'home_team_external_id' => 'TeAm0001', 'away_team_external_id' => 'TeAm0002',
                        'kickoff_at' => now()->subDay()->toIso8601ZuluString(), 'status' => 'finished', 'period' => null,
                        'home_goals' => 2, 'away_goals' => 1,
                    ],
                ]],
            ]));
        }

        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        Competition::query()->update(['status' => CompetitionStatus::Live, 'ingest_enabled' => true]);
    }

    public function test_run_scheduled_fixtures_uses_short_window_and_scheduled_trigger(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.fixtures.days_back' => 2, 'ingest.schedule.fixtures.days_forward' => 3]);

        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures'])->assertSuccessful();

        $sent = $publisher->sent[0];
        $this->assertSame('sync_fixtures', $sent['job_type']);
        $this->assertSame('SeAs2026', $sent['payload']['season_external_id']);
        $this->assertSame([
            'from' => now('Europe/Moscow')->subDays(2)->toDateString(),
            'to' => now('Europe/Moscow')->addDays(3)->toDateString(),
        ], $sent['payload']['window']);
        $this->assertSame('schedule', IngestionRun::latest('id')->first()->stats['context']['trigger']);
    }

    public function test_run_scheduled_window_uses_moscow_date_not_utc(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.fixtures.days_back' => 2, 'ingest.schedule.fixtures.days_forward' => 3]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-27 22:30:00', 'UTC'));

        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures'])->assertSuccessful();

        $this->assertSame(['from' => '2026-11-26', 'to' => '2026-12-01'], $publisher->sent[0]['payload']['window']);
    }

    public function test_run_scheduled_skips_disabled_competitions_unless_listed_explicitly(): void
    {
        $this->seedCompetition();
        Competition::query()->update(['ingest_enabled' => false]);
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures_full'])->assertSuccessful();
        $this->assertSame([], $publisher->sent);

        config(['ingest.schedule.competitions' => ['premier-league']]);
        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures_full'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent);
        $this->assertArrayNotHasKey('window', $publisher->sent[0]['payload']);
    }

    public function test_ingest_flag_is_independent_from_display_status(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();

        Competition::query()->update(['status' => CompetitionStatus::Hidden]);
        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures_full'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent, 'hidden + ingest_enabled грузится');

        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        Competition::query()->update(['status' => CompetitionStatus::Live, 'ingest_enabled' => false]);
        $this->artisan('ingest:run-scheduled', ['job' => 'fixtures_full'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent, 'live без ingest_enabled не грузится');
    }

    public function test_new_competitions_are_disabled_by_default_and_migration_enables_rpl_and_cup(): void
    {
        $competition = Competition::create(['slug' => 'x', 'name' => 'X', 'type' => 'league']);
        $this->assertFalse($competition->fresh()->ingest_enabled);

        Competition::create(['slug' => 'premier-league', 'name' => 'RPL', 'type' => 'league']);
        Competition::create(['slug' => 'russian-cup', 'name' => 'Cup', 'type' => 'cup']);
        foreach (['fnl', 'champions-league', 'europa-league', 'conference-league', 'premier-league-gb-eng', 'laliga', 'bundesliga', 'serie-a'] as $slug) {
            Competition::create(['slug' => $slug, 'name' => $slug, 'type' => 'league', 'status' => 'hidden']);
        }
        $this->seed(\Database\Seeders\IngestFlagsSeeder::class);

        $this->assertSame(
            ['bundesliga', 'champions-league', 'conference-league', 'europa-league', 'fnl', 'laliga', 'premier-league', 'premier-league-gb-eng', 'russian-cup', 'serie-a'],
            Competition::where('ingest_enabled', true)->orderBy('slug')->pluck('slug')->all(),
        );
        $fnl = Competition::where('slug', 'fnl')->first();
        $this->assertSame(CompetitionStatus::Live, $fnl->status);
        $this->assertSame('ФНЛ', $fnl->short_name_i18n['ru']);

        $ucl = Competition::where('slug', 'champions-league')->first();
        $this->assertSame(CompetitionStatus::Live, $ucl->status);
        $this->assertSame('tournament', $ucl->type->value);
        $this->assertSame('continental', $ucl->scope->value);
        $this->assertSame('Лига чемпионов', $ucl->name_i18n['ru']);
        $this->assertSame('league', Competition::where('slug', 'serie-a')->first()->type->value);
    }

    public function test_every_competition_job_respects_ingest_enabled(): void
    {
        $this->seedCompetition(finished: true);
        Competition::query()->update(['ingest_enabled' => false]);
        $publisher = $this->fakePublisher();

        foreach (['fixtures', 'fixtures_full', 'details_missing', 'details_recent', 'details_live', 'structure', 'squads'] as $job) {
            $this->artisan('ingest:run-scheduled', ['job' => $job])->assertSuccessful();
        }
        $this->assertSame([], $publisher->sent);

        Competition::query()->update(['ingest_enabled' => true]);
        foreach (['fixtures', 'fixtures_full', 'details_missing', 'details_recent', 'details_live', 'structure', 'squads'] as $i => $job) {
            IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
            if ($job === 'details_live') {
                FootballMatch::query()->update(['status' => MatchStatus::Live, 'kickoff_at' => now()->subHour()]);
            }
            $this->artisan('ingest:run-scheduled', ['job' => $job])->assertSuccessful();
            $this->assertCount($i + 1, $publisher->sent, $job);
        }
    }

    public function test_squads_skip_teams_with_ingest_disabled(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();
        \App\Models\Team::where('name', 'Spartak Moscow')->update(['ingest_enabled' => false]);

        $this->artisan('ingest:run-scheduled', ['job' => 'squads'])->assertSuccessful();

        $this->assertSame(['TeAm0001'], $publisher->sent[0]['payload']['team_external_ids']);
    }

    public function test_run_scheduled_does_not_fail_when_previous_run_is_still_active(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'structure'])->assertSuccessful();
        $this->artisan('ingest:run-scheduled', ['job' => 'structure'])->assertSuccessful();

        $this->assertCount(1, $publisher->sent, 'второй вызов пропущен: прогон ещё идёт');
    }

    public function test_run_scheduled_squads_sends_season_teams_of_listed_competitions(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'squads'])->assertSuccessful();

        $sent = $publisher->sent[0];
        $this->assertSame('sync_squads', $sent['job_type']);
        $this->assertSame('SeAs2026', $sent['payload']['season_external_id']);
        $this->assertSame(['TeAm0001', 'TeAm0002'], $sent['payload']['team_external_ids']);
        $this->assertSame('schedule', $sent['payload']['context']['trigger']);

        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        config(['ingest.schedule.squads.competitions' => ['no-such-cup']]);
        $this->artisan('ingest:run-scheduled', ['job' => 'squads'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent);
    }

    public function test_dispatch_sync_squads_accepts_explicit_teams_by_slug_or_external_id(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();
        $slug = \App\Models\Team::where('name', 'Zenit')->value('slug');

        $this->artisan('ingest:dispatch', ['type' => 'sync_squads', '--competition' => 'premier-league', '--team' => [$slug, 'TeAm0002']])->assertSuccessful();
        $this->assertSame(['TeAm0001', 'TeAm0002'], $publisher->sent[0]['payload']['team_external_ids']);

        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        $this->artisan('ingest:dispatch', ['type' => 'sync_squads', '--competition' => 'premier-league', '--team' => ['unknown']])->assertFailed();
        $this->assertCount(1, $publisher->sent);
    }

    public function test_run_scheduled_details_missing_and_recent(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.details_missing.limit' => 7]);

        $this->artisan('ingest:run-scheduled', ['job' => 'details_missing'])->assertSuccessful();
        $this->assertSame(['Mat00001'], $publisher->sent[0]['payload']['match_external_ids']);
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);

        $this->artisan('ingest:run-scheduled', ['job' => 'details_recent'])->assertSuccessful();
        $this->assertSame(['Mat00001'], $publisher->sent[1]['payload']['match_external_ids']);
    }

    public function test_run_scheduled_details_live_takes_only_live_matches_of_enabled_competitions(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'details_live'])->assertSuccessful();
        $this->assertSame([], $publisher->sent, 'завершённый матч не live — нечего грузить, не ошибка');

        FootballMatch::query()->update(['status' => MatchStatus::Live, 'kickoff_at' => now()->subMinutes(50)]);
        $this->artisan('ingest:run-scheduled', ['job' => 'details_live'])->assertSuccessful();

        $sent = $publisher->sent[0];
        $this->assertSame('sync_match_details', $sent['job_type']);
        $this->assertSame(['Mat00001'], $sent['payload']['match_external_ids']);
        $this->assertSame('schedule', $sent['payload']['context']['trigger']);
    }

    public function test_details_live_ignores_matches_stuck_in_live_status(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.details_live.max_age_hours' => 6]);

        FootballMatch::query()->update(['status' => MatchStatus::Live, 'kickoff_at' => now()->subHours(7)]);
        $this->artisan('ingest:run-scheduled', ['job' => 'details_live'])->assertSuccessful();

        $this->assertSame([], $publisher->sent);
    }

    public function test_details_live_does_not_touch_finished_selection(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'details_missing'])->assertSuccessful();

        $this->assertSame(['Mat00001'], $publisher->sent[0]['payload']['match_external_ids']);
        $this->artisan('ingest:dispatch', ['type' => 'sync_match_details', '--competition' => 'premier-league', '--live' => true, '--missing' => true])->assertFailed();
    }

    public function test_details_live_respects_limit(): void
    {
        $this->seedCompetition(finished: true);
        $publisher = $this->fakePublisher();
        app(ApplyIngestionResult::class)->handle($this->message($this->newRun(IngestionKind::SyncFixtures, now()), [
            'kind' => 'batch', 'seq' => 1,
            'entities' => [[
                'entity_type' => 'match', 'external_id' => 'Mat00002',
                'data' => [
                    'competition_external_id' => 'AbCd1234', 'season_external_id' => 'SeAs2026', 'stage_external_id' => 'StG00001',
                    'round_name' => 'Round 2', 'round_number' => 2,
                    'home_team_external_id' => 'TeAm0002', 'away_team_external_id' => 'TeAm0001',
                    'kickoff_at' => now()->subHour()->toIso8601ZuluString(), 'status' => 'finished', 'period' => null,
                    'home_goals' => 0, 'away_goals' => 0,
                ],
            ]],
        ]));
        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        FootballMatch::query()->update(['status' => MatchStatus::Live]);
        FootballMatch::query()->whereHas('homeTeam', fn ($q) => $q->where('name', 'Zenit'))->update(['kickoff_at' => now()->subMinutes(90)]);
        config(['ingest.schedule.details_live.limit' => 1]);

        $this->artisan('ingest:run-scheduled', ['job' => 'details_live'])->assertSuccessful();

        $this->assertSame(['Mat00001'], $publisher->sent[0]['payload']['match_external_ids'], 'давнее начало первым, лимит 1');
    }

    public function test_cron_offset_shifts_start_inside_the_hour(): void
    {
        $this->assertSame('*/15 * * * *', IngestSchedule::cron(15));
        $this->assertSame('5-59/15 * * * *', IngestSchedule::cron(15, 5));
        $this->assertSame('*/15 * * * *', IngestSchedule::cron(15, 15));
        $this->assertSame('5 * * * *', IngestSchedule::cron(60, 5));
        $this->assertSame('0 * * * *', IngestSchedule::cron(60, 60));
        $this->assertSame('10 */3 * * *', IngestSchedule::cron(180, 10));
        $this->assertSame('10 0 * * *', IngestSchedule::cron(1440, 10));
    }

    public function test_details_jobs_never_start_in_the_same_minute(): void
    {
        $crons = $this->crons();
        $minutes = function (string $cron): array {
            $field = explode(' ', $cron)[0];
            if (preg_match('#^(?:\*|(\d+)-59)/(\d+)$#', $field, $m)) {
                return range((int) ($m[1] ?: 0), 59, (int) $m[2]);
            }

            return [(int) $field];
        };

        $missing = $minutes($crons["ingest:run-scheduled 'details_missing'"]);
        $recent = $minutes($crons["ingest:run-scheduled 'details_recent'"]);
        $live = $minutes($crons["ingest:run-scheduled 'details_live'"]);

        $this->assertSame([], array_intersect($missing, $recent), 'details_recent не совпадает с details_missing');
        $this->assertSame([], array_intersect($live, $recent), 'details_recent не совпадает с details_live');
        $this->assertSame([], array_intersect($missing, $live), 'details_live не совпадает с details_missing');
    }

    public function test_run_scheduled_details_missing_without_candidates_is_not_an_error(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();

        $this->artisan('ingest:run-scheduled', ['job' => 'details_missing'])->assertSuccessful();

        $this->assertSame([], $publisher->sent);
    }

    public function test_run_scheduled_catalog_uses_enabled_competitions_by_default(): void
    {
        $this->seedCompetition();
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.catalog.country' => '', 'ingest.schedule.catalog.league' => '']);
        IngestionRun::query()->delete();

        $this->artisan('ingest:run-scheduled', ['job' => 'catalog'])->assertSuccessful();
        $filters = $publisher->sent[0]['payload']['filters'];
        $this->assertSame(['AbCd1234'], $filters['league_ids']);
        $this->assertSame('', $filters['country']);

        IngestionRun::query()->update(['status' => IngestionStatus::Ok, 'finished_at' => now()]);
        Competition::query()->update(['ingest_enabled' => false]);
        $this->artisan('ingest:run-scheduled', ['job' => 'catalog'])->assertSuccessful();
        $this->assertCount(1, $publisher->sent, 'нет включённых соревнований и нет env-фильтров — пропуск');
    }

    public function test_run_scheduled_catalog_uses_configured_filters(): void
    {
        $publisher = $this->fakePublisher();
        config(['ingest.schedule.catalog.country' => 'Russia', 'ingest.schedule.catalog.league' => 'Premier League']);

        $this->artisan('ingest:run-scheduled', ['job' => 'catalog'])->assertSuccessful();

        $filters = $publisher->sent[0]['payload']['filters'];
        $this->assertSame('Russia', $filters['country']);
        $this->assertSame('Premier League', $filters['league_name']);
    }

    public function test_run_scheduled_rejects_unknown_job(): void
    {
        $this->artisan('ingest:run-scheduled', ['job' => 'nope'])->assertFailed();
    }

    public function test_batch_waits_for_preceding_batch_then_proceeds_after_timeout(): void
    {
        config(['rabbitmq.ingest.batch_order_wait_seconds' => 1]);
        $run = $this->newRun(IngestionKind::SyncCatalog, now());
        $apply = app(ApplyIngestionResult::class);

        $started = microtime(true);
        $apply->handle($this->message($run, [
            'kind' => 'batch', 'seq' => 2,
            'entities' => [['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']]],
        ]));

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started, 'батч 2 ждал отсутствующий батч 1');
        $this->assertSame([2], $run->fresh()->stats['context']['batches_seen']);

        $started = microtime(true);
        $apply->handle($this->message($run, ['kind' => 'batch', 'seq' => 3, 'entities' => []]));
        $this->assertLessThan(0.5, microtime(true) - $started, 'батч 3 не ждёт: батч 2 уже применён');
    }
}
