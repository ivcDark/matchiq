<?php

namespace Tests\Feature\Database;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\IngestionKind;
use App\Enums\IngestionPhase;
use App\Enums\IngestionStatus;
use App\Enums\MatchStatus;
use App\Enums\PickStatus;
use App\Enums\StageType;
use App\Models\Competition;
use App\Models\Country;
use App\Models\ExternalId;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\MarketType;
use App\Models\MatchPrediction;
use App\Models\ModelVersion;
use App\Models\Pick;
use App\Models\PredictionOutcome;
use App\Models\Round;
use App\Models\Season;
use App\Models\SourceProvider;
use App\Models\Stage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function seedMatch(): FootballMatch
    {
        $country = Country::create(['code' => 'RU', 'name' => 'Russia', 'name_i18n' => ['ru' => 'Россия']]);
        $competition = Competition::create([
            'country_id' => $country->id,
            'slug' => 'rpl',
            'name' => 'Russian Premier League',
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
        ]);
        $season = Season::create([
            'competition_id' => $competition->id,
            'label' => '2026/27',
            'slug' => '2026-27',
            'is_current' => true,
        ]);
        $stage = Stage::create([
            'season_id' => $season->id,
            'name' => 'Regular season',
            'type' => StageType::League,
            'cycles' => 2,
            'has_standings' => true,
        ]);
        $round = Round::create(['stage_id' => $stage->id, 'number' => 1, 'type' => 'matchday']);

        $home = Team::create(['country_id' => $country->id, 'slug' => 'zenit', 'name' => 'Zenit', 'name_i18n' => ['ru' => 'Зенит']]);
        $away = Team::create(['country_id' => $country->id, 'slug' => 'cska', 'name' => 'CSKA']);

        return FootballMatch::create([
            'competition_id' => $competition->id,
            'season_id' => $season->id,
            'stage_id' => $stage->id,
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'kickoff_at' => now()->addDay(),
            'status' => MatchStatus::Scheduled,
        ]);
    }

    public function test_match_graph_and_casts(): void
    {
        $match = $this->seedMatch()->fresh();

        $this->assertSame(MatchStatus::Scheduled, $match->status);
        $this->assertSame('Зенит', $match->homeTeam->localized('name', 'ru'));
        $this->assertSame('CSKA', $match->awayTeam->localized('name', 'ru'));
        $this->assertSame('rpl', $match->competition->slug);
        $this->assertTrue($match->season->competition->is($match->competition));
        $this->assertSame(['ru' => 'Россия'], $match->competition->country->name_i18n);
    }

    public function test_only_one_current_season_per_competition(): void
    {
        $match = $this->seedMatch();

        $this->expectException(QueryException::class);

        Season::create([
            'competition_id' => $match->competition_id,
            'label' => '2027/28',
            'slug' => '2027-28',
            'is_current' => true,
        ]);
    }

    public function test_only_one_current_prediction_per_match(): void
    {
        $match = $this->seedMatch();
        $version = ModelVersion::create(['code' => 'poisson_v1', 'name' => 'Poisson', 'version' => '1']);

        MatchPrediction::create([
            'match_id' => $match->id,
            'model_version_id' => $version->id,
            'is_current' => true,
            'generated_at' => now(),
        ]);

        $this->assertNotNull($match->currentPrediction);

        $this->expectException(QueryException::class);

        MatchPrediction::create([
            'match_id' => $match->id,
            'model_version_id' => $version->id,
            'is_current' => true,
            'generated_at' => now(),
        ]);
    }

    public function test_pick_replacement_keeps_history_with_one_active_pick(): void
    {
        $match = $this->seedMatch();
        $version = ModelVersion::create(['code' => 'poisson_v1', 'name' => 'Poisson', 'version' => '1']);
        $prediction = MatchPrediction::create([
            'match_id' => $match->id,
            'model_version_id' => $version->id,
            'is_current' => true,
            'generated_at' => now(),
        ]);
        $market = MarketType::create(['code' => 'result_1x2', 'category' => 'results', 'name' => 'Match result']);
        $home = PredictionOutcome::create([
            'match_prediction_id' => $prediction->id,
            'market_type_id' => $market->id,
            'outcome_key' => 'home',
            'probability' => 0.5,
        ]);
        $draw = PredictionOutcome::create([
            'match_prediction_id' => $prediction->id,
            'market_type_id' => $market->id,
            'outcome_key' => 'draw',
            'probability' => 0.25,
        ]);
        $user = User::factory()->create();

        $make = fn (PredictionOutcome $outcome) => Pick::create([
            'user_id' => $user->id,
            'match_id' => $match->id,
            'prediction_outcome_id' => $outcome->id,
            'market_type_id' => $market->id,
            'outcome_key' => $outcome->outcome_key,
            'probability_at_pick' => $outcome->probability,
            'picked_at' => now(),
        ]);

        $first = $make($home);

        try {
            DB::transaction(fn () => $make($draw));
            $this->fail('Второй активный pick на матч должен отклоняться partial unique-индексом.');
        } catch (QueryException) {
        }

        $first->update(['superseded_at' => now()]);
        $second = $make($draw);

        $this->assertSame(2, $user->picks()->count());
        $this->assertSame([$second->id], $user->picks()->active()->pluck('id')->all());
        $this->assertSame(PickStatus::Pending, $second->fresh()->status);
    }

    public function test_external_ids_resolve_polymorphic_entity_via_morph_map(): void
    {
        $match = $this->seedMatch();
        $team = $match->homeTeam;
        $provider = SourceProvider::create(['code' => 'manual', 'name' => 'Manual', 'kind' => 'manual']);

        $team->externalIds()->create([
            'source_provider_id' => $provider->id,
            'entity_type' => 'team',
            'external_id' => 'abc-1',
        ]);

        $external = ExternalId::query()->where('external_id', 'abc-1')->firstOrFail();

        $this->assertSame('team', $external->getRawOriginal('entity_type'));
        $this->assertTrue($external->entity->is($team));
    }

    public function test_ingestion_run_keeps_per_entity_errors_and_last_run_on_external_id(): void
    {
        $team = $this->seedMatch()->homeTeam;
        $provider = SourceProvider::create(['code' => 'manual', 'name' => 'Manual', 'kind' => 'manual']);
        $run = IngestionRun::create([
            'source_provider_id' => $provider->id,
            'kind' => IngestionKind::Schedule,
            'status' => IngestionStatus::Partial,
            'started_at' => now(),
            'stats' => ['created' => 1, 'updated' => 0, 'failed' => 1],
        ]);
        $run->errors()->create([
            'entity_type' => 'team',
            'external_id' => 'bad-1',
            'phase' => IngestionPhase::Match,
            'message' => 'Команда не сопоставлена',
            'context' => ['name' => 'Unknown FC'],
        ]);
        $external = $team->externalIds()->create([
            'source_provider_id' => $provider->id,
            'entity_type' => 'team',
            'external_id' => 'ok-1',
            'last_ingestion_run_id' => $run->id,
        ]);

        $error = $run->errors()->firstOrFail();
        $this->assertSame(IngestionPhase::Match, $error->phase);
        $this->assertSame('Unknown FC', $error->context['name']);
        $this->assertTrue($external->lastIngestionRun->is($run));

        $run->delete();
        $this->assertSame(0, $run->errors()->count());
        $this->assertNull($external->fresh()->last_ingestion_run_id);
    }

    public function test_translation_helper_falls_back_to_canonical_name(): void
    {
        $team = new Team(['name' => 'CSKA', 'name_i18n' => ['ru' => 'ЦСКА', 'pt' => 'CSKA PT']]);

        $this->assertSame('ЦСКА', $team->localized('name', 'ru'));
        $this->assertSame('CSKA PT', $team->localized('name', 'pt-BR'));
        $this->assertSame('CSKA', $team->localized('name', 'es'));
        $this->assertSame('CSKA', $team->setTranslation('name', 'en', 'ignored')->localized('name', 'en'));
    }
}
