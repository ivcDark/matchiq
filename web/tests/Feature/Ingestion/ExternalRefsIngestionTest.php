<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\ResultMessage;
use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\SourceProviderCode;
use App\Models\ExternalId;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\Team;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalRefsIngestionTest extends TestCase
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

    private function seedMatch(): void
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
                'kickoff_at' => '2026-11-27T16:30:00Z', 'status' => 'live', 'period' => 'first_half',
                'home_goals' => 1, 'away_goals' => 0, 'home_goals_ht' => null, 'away_goals_ht' => null,
                'home_goals_et' => null, 'away_goals_et' => null, 'home_pens' => null, 'away_pens' => null,
            ],
        ]]);
    }

    private function refs(string $type, string $externalId, array $refs): array
    {
        return ['entity_type' => 'external_refs', 'external_id' => $externalId, 'data' => ['entity_type' => $type, 'refs' => $refs]];
    }

    private function idOf(SourceProviderCode $code, ExternalEntityType $type, int $entityId): ?string
    {
        $provider = app(SourceProviderRepository::class)->findByCode($code->value);

        return $provider === null ? null : app(ExternalIdRepository::class)->externalIdFor($provider->id, $type, $entityId);
    }

    public function test_stores_sstats_flashscore_and_pari_ids_for_match_team_and_competition(): void
    {
        $this->seedMatch();
        $match = FootballMatch::firstOrFail();
        $team = Team::where('name', 'Torpedo')->firstOrFail();

        $run = $this->apply(IngestionKind::SyncFixtures, [
            $this->refs('match', 'IPBoDE9D', ['sstats_api' => '1577610', 'flashscore' => 'IPBoDE9D', 'pari' => '70159794']),
            $this->refs('team', 'TeAm0001', ['sstats_api' => '3985', 'flashscore' => 'TeAm0001', 'pari' => '420773']),
            $this->refs('competition', 'AbCd1234', ['sstats_api' => '236', 'flashscore' => 'AbCd1234']),
        ]);

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $this->assertSame(3, $run->stats['by_entity']['external_refs']['created']);
        $this->assertSame(8, $run->stats['by_entity']['external_ref']['created']);

        $this->assertSame('1577610', $this->idOf(SourceProviderCode::SstatsApi, ExternalEntityType::Match, $match->id));
        $this->assertSame('IPBoDE9D', $this->idOf(SourceProviderCode::Flashscore, ExternalEntityType::Match, $match->id));
        $this->assertSame('70159794', $this->idOf(SourceProviderCode::Pari, ExternalEntityType::Match, $match->id));
        $this->assertSame('3985', $this->idOf(SourceProviderCode::SstatsApi, ExternalEntityType::Team, $team->id));
        $this->assertSame('420773', $this->idOf(SourceProviderCode::Pari, ExternalEntityType::Team, $team->id));
        $this->assertNull($this->idOf(SourceProviderCode::Pari, ExternalEntityType::Team, Team::where('name', 'SKA')->firstOrFail()->id));

        // Ключ ingest не тронут: по нему по-прежнему находится матч.
        $this->assertSame('IPBoDE9D', $this->idOf(SourceProviderCode::Sstats, ExternalEntityType::Match, $match->id));
    }

    public function test_repeat_is_unchanged_and_changed_id_is_replaced(): void
    {
        $this->seedMatch();
        $match = FootballMatch::firstOrFail();
        $entity = fn (string $pari) => $this->refs('match', 'IPBoDE9D', ['sstats_api' => '1577610', 'pari' => $pari]);

        $this->apply(IngestionKind::SyncFixtures, [$entity('111')]);
        $rows = ExternalId::count();

        $second = $this->apply(IngestionKind::SyncFixtures, [$entity('111')]);
        $this->assertSame(1, $second->stats['by_entity']['external_refs']['unchanged']);
        $this->assertSame($rows, ExternalId::count());

        $third = $this->apply(IngestionKind::SyncFixtures, [$entity('222')]);
        $this->assertSame(1, $third->stats['by_entity']['external_refs']['updated']);
        $this->assertSame($rows, ExternalId::count());
        $this->assertSame('222', $this->idOf(SourceProviderCode::Pari, ExternalEntityType::Match, $match->id));
    }

    public function test_rejects_unknown_entity_missing_target_and_foreign_owner(): void
    {
        $this->seedMatch();

        $run = $this->apply(IngestionKind::SyncFixtures, [
            $this->refs('match', 'NoSuchMatch', ['sstats_api' => '1']),
            $this->refs('match', 'IPBoDE9D', ['sstats' => 'IPBoDE9D']),
            $this->refs('player', 'IPBoDE9D', ['sstats_api' => '1']),
            $this->refs('match', 'IPBoDE9D', ['pari' => ' ']),
        ]);
        $this->assertSame(4, $run->stats['by_entity']['external_refs']['failed']);
        $this->assertSame(0, ExternalId::whereHas('sourceProvider', fn ($q) => $q->where('code', 'sstats_api'))->count());

        $other = $this->apply(IngestionKind::SyncFixtures, [[
            'entity_type' => 'match', 'external_id' => 'Other001',
            'data' => [
                'competition_external_id' => 'AbCd1234', 'season_external_id' => 'SeAs2026', 'stage_external_id' => 'StG00001',
                'round_name' => 'Round 21', 'round_number' => 21, 'home_team_external_id' => 'TeAm0002', 'away_team_external_id' => 'TeAm0001',
                'kickoff_at' => '2026-12-04T16:30:00Z', 'status' => 'scheduled', 'period' => null,
                'home_goals' => null, 'away_goals' => null, 'home_goals_ht' => null, 'away_goals_ht' => null,
                'home_goals_et' => null, 'away_goals_et' => null, 'home_pens' => null, 'away_pens' => null,
            ],
        ]]);
        $this->assertSame(IngestionStatus::Ok, $other->status);

        $this->apply(IngestionKind::SyncFixtures, [$this->refs('match', 'IPBoDE9D', ['sstats_api' => '1577610'])]);
        $conflict = $this->apply(IngestionKind::SyncFixtures, [$this->refs('match', 'Other001', ['sstats_api' => '1577610'])]);
        $this->assertSame(1, $conflict->stats['by_entity']['external_refs']['failed']);
    }
}
