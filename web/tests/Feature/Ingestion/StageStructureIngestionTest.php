<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\ResultMessage;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\RoundCode;
use App\Enums\RoundType;
use App\Enums\SourceProviderCode;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\Round;
use App\Models\Stage;
use App\Models\StageGroup;
use App\Models\StageTeam;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageStructureIngestionTest extends TestCase
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

    private function send(IngestionRun $run, array $entities): void
    {
        $apply = app(ApplyIngestionResult::class);
        $apply->handle(ResultMessage::fromArray([
            'schema_version' => 1, 'message_id' => 'm', 'run_id' => $run->id, 'job_type' => $run->kind->value,
            'provider' => 'sstats', 'kind' => 'batch', 'seq' => 1, 'entities' => $entities,
        ]));
    }

    private function match(string $id, string $stage, string $home, string $away, array $extra = []): array
    {
        return ['entity_type' => 'match', 'external_id' => $id, 'data' => $extra + [
            'season_external_id' => 'SeAs2026', 'stage_external_id' => $stage,
            'round_name' => null, 'round_number' => null,
            'home_team_external_id' => $home, 'away_team_external_id' => $away,
            'kickoff_at' => '2026-07-24T14:00:00Z', 'status' => 'scheduled', 'period' => null,
            'home_goals' => null, 'away_goals' => null, 'home_goals_ht' => null, 'away_goals_ht' => null,
            'home_goals_et' => null, 'away_goals_et' => null, 'home_pens' => null, 'away_pens' => null,
        ]];
    }

    private function seedCatalog(): void
    {
        $this->send($this->newRun(IngestionKind::SyncCatalog), [
            ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
            ['entity_type' => 'competition', 'external_id' => 'CuP00001', 'data' => ['country_external_id' => '189', 'name' => 'Cup', 'type' => 'league', 'scope' => 'domestic']],
            ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'CuP00001', 'label' => '2026-2027', 'is_current' => true]],
        ]);
    }

    private function structureEntities(): array
    {
        $teams = [];
        foreach (['TeAm0001', 'TeAm0002', 'TeAm0003', 'TeAm0004'] as $id) {
            $teams[] = ['entity_type' => 'team', 'external_id' => $id, 'data' => ['season_external_id' => 'SeAs2026', 'name' => $id, 'country_external_id' => '189']];
        }

        return [
            ...$teams,
            ['entity_type' => 'stage', 'external_id' => 'GrStage1', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Group Stage', 'type' => 'group', 'stage_order' => 1, 'has_standings' => true]],
            ['entity_type' => 'stage', 'external_id' => 'KoStage1', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Play Offs', 'type' => 'knockout', 'stage_order' => 2, 'has_standings' => false]],
            ['entity_type' => 'stage_team', 'external_id' => 'GrStage1:TeAm0001', 'data' => ['stage_external_id' => 'GrStage1', 'team_external_id' => 'TeAm0001', 'group_name' => 'Group A']],
            ['entity_type' => 'stage_team', 'external_id' => 'GrStage1:TeAm0002', 'data' => ['stage_external_id' => 'GrStage1', 'team_external_id' => 'TeAm0002', 'group_name' => 'Group A']],
            ['entity_type' => 'stage_team', 'external_id' => 'GrStage1:TeAm0003', 'data' => ['stage_external_id' => 'GrStage1', 'team_external_id' => 'TeAm0003', 'group_name' => 'Group B']],
            ['entity_type' => 'stage_team', 'external_id' => 'GrStage1:TeAm0004', 'data' => ['stage_external_id' => 'GrStage1', 'team_external_id' => 'TeAm0004', 'group_name' => 'Group B']],
            ['entity_type' => 'stage_team', 'external_id' => 'KoStage1:TeAm0001', 'data' => ['stage_external_id' => 'KoStage1', 'team_external_id' => 'TeAm0001']],
            ['entity_type' => 'stage_group', 'external_id' => '1', 'data' => ['stage_external_id' => 'GrStage1', 'name' => 'Group A', 'group_order' => 1]],
            ['entity_type' => 'stage_group', 'external_id' => '2', 'data' => ['stage_external_id' => 'GrStage1', 'name' => 'Group B', 'group_order' => 2]],
            ['entity_type' => 'round', 'external_id' => '101', 'data' => ['stage_external_id' => 'KoStage1', 'number' => 1, 'name' => 'Quarter-finals', 'type' => 'knockout_round', 'code' => 'QF']],
        ];
    }

    public function test_structure_creates_groups_stage_teams_and_named_rounds_independent_of_order(): void
    {
        $this->seedCatalog();
        $this->send($this->newRun(IngestionKind::SyncStructure), $this->structureEntities());

        $this->assertSame(['Group A', 'Group B'], StageGroup::orderBy('group_order')->pluck('name')->all());
        $this->assertSame(5, StageTeam::count());

        $groupA = StageGroup::firstWhere('name', 'Group A');
        $this->assertSame(2, StageTeam::where('stage_group_id', $groupA->id)->count());
        $this->assertSame(1, StageTeam::whereNull('stage_group_id')->count());

        $round = Round::firstWhere('name', 'Quarter-finals');
        $this->assertSame(RoundCode::QuarterFinal, $round->code);
        $this->assertSame(RoundType::KnockoutRound, $round->type);
    }

    public function test_group_stage_gets_configured_competition_rules_and_keeps_them_on_repeat(): void
    {
        $rules = ['points' => ['win' => 3, 'draw' => 1, 'loss' => 0, 'penalty_win' => 2, 'penalty_loss' => 1]];
        config(['matchiq.stage_rules.cup' => $rules]);
        $this->seedCatalog();
        $this->send($this->newRun(IngestionKind::SyncStructure), $this->structureEntities());
        $this->send($this->newRun(IngestionKind::SyncStructure), $this->structureEntities());

        $this->assertSame($rules, Stage::firstWhere('name', 'Group Stage')->rules);
        $this->assertSame([], Stage::firstWhere('name', 'Play Offs')->rules);
    }

    public function test_matches_get_group_and_named_round_and_repeat_is_idempotent(): void
    {
        $this->seedCatalog();
        $structure = $this->newRun(IngestionKind::SyncStructure);
        $this->send($structure, $this->structureEntities());

        $fixtures = [
            $this->match('Mat00001', 'GrStage1', 'TeAm0001', 'TeAm0002', ['round_name' => 'Round 1', 'round_number' => 1]),
            $this->match('Mat00002', 'GrStage1', 'TeAm0003', 'TeAm0004', ['round_name' => 'Round 1', 'round_number' => 1]),
            $this->match('Mat00003', 'KoStage1', 'TeAm0001', 'TeAm0003', ['round_external_id' => '101', 'round_name' => 'Quarter-finals']),
        ];
        $this->send($this->newRun(IngestionKind::SyncFixtures), $fixtures);

        $groups = StageGroup::pluck('id', 'name');
        $this->assertSame($groups['Group A'], FootballMatch::whereHas('externalIds', fn ($q) => $q->where('external_id', 'Mat00001'))->first()->stage_group_id);
        $this->assertSame($groups['Group B'], FootballMatch::whereHas('externalIds', fn ($q) => $q->where('external_id', 'Mat00002'))->first()->stage_group_id);

        $knockout = FootballMatch::whereHas('externalIds', fn ($q) => $q->where('external_id', 'Mat00003'))->first();
        $this->assertNull($knockout->stage_group_id);
        $this->assertSame('Quarter-finals', $knockout->round->name);
        $this->assertSame(Stage::firstWhere('name', 'Play Offs')->id, $knockout->round->stage_id);

        $again = $this->newRun(IngestionKind::SyncStructure);
        $this->send($again, $this->structureEntities());
        $this->assertSame(0, $again->refresh()->stats['created']);
        $this->assertSame(2, StageGroup::count());
        $this->assertSame(5, StageTeam::count());
    }

    public function test_groups_are_assigned_to_matches_loaded_before_structure(): void
    {
        $this->seedCatalog();
        $entities = $this->structureEntities();
        $stagesAndTeams = array_values(array_filter($entities, fn ($e) => in_array($e['entity_type'], ['team', 'stage'], true)));
        $rest = array_values(array_filter($entities, fn ($e) => ! in_array($e['entity_type'], ['team', 'stage'], true)));

        $this->send($this->newRun(IngestionKind::SyncStructure), $stagesAndTeams);
        $this->send($this->newRun(IngestionKind::SyncFixtures), [$this->match('Mat00001', 'GrStage1', 'TeAm0001', 'TeAm0002')]);
        $this->assertNull(FootballMatch::firstOrFail()->stage_group_id);

        $this->send($this->newRun(IngestionKind::SyncStructure), $rest);
        $this->assertSame(StageGroup::firstWhere('name', 'Group A')->id, FootballMatch::firstOrFail()->stage_group_id);
    }
}
