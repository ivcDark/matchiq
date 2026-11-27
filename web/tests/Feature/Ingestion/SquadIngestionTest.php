<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\ResultMessage;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\PlayerPosition;
use App\Enums\SourceProviderCode;
use App\Models\IngestionRun;
use App\Models\Player;
use App\Models\SquadMembership;
use App\Models\Team;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquadIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function apply(IngestionKind $kind, array $entities, array $errors = []): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);
        $run = IngestionRun::create(['source_provider_id' => $provider->id, 'kind' => $kind, 'status' => IngestionStatus::Running, 'started_at' => now()]);
        $apply = app(ApplyIngestionResult::class);
        $base = ['schema_version' => 1, 'message_id' => 'm', 'run_id' => $run->id, 'job_type' => $kind->value, 'provider' => 'sstats'];
        $apply->handle(ResultMessage::fromArray($base + ['kind' => 'batch', 'seq' => 1, 'entities' => $entities, 'errors' => $errors]));
        $apply->handle(ResultMessage::fromArray($base + ['kind' => 'done', 'batches_total' => 1, 'source' => ['requests' => 1, 'rate_limited' => 0]]));

        return $run->refresh();
    }

    private function seedTeams(): void
    {
        $this->apply(IngestionKind::SyncCatalog, [
            ['entity_type' => 'country', 'external_id' => '189', 'data' => ['code' => 'RU', 'name' => 'Russia', 'kind' => 'country']],
            ['entity_type' => 'competition', 'external_id' => 'AbCd1234', 'data' => ['country_external_id' => '189', 'name' => 'Premier League', 'type' => 'league', 'scope' => 'domestic']],
            ['entity_type' => 'season', 'external_id' => 'SeAs2026', 'data' => ['competition_external_id' => 'AbCd1234', 'label' => '2026-2027', 'is_current' => true]],
        ]);
        $this->apply(IngestionKind::SyncStructure, [
            ['entity_type' => 'team', 'external_id' => 'TeAm0001', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Zenit', 'country_external_id' => '189']],
            ['entity_type' => 'team', 'external_id' => 'TeAm0002', 'data' => ['season_external_id' => 'SeAs2026', 'name' => 'Spartak Moscow', 'country_external_id' => '189']],
        ]);
    }

    private function player(string $id, string $name, string $team, ?string $position, bool $profile = true): array
    {
        return [
            'entity_type' => 'player', 'external_id' => $id,
            'data' => ['name' => $name, 'team_external_id' => $team, 'position' => $position, 'has_profile' => $profile, 'birth_date' => $profile ? '1999-02-02' : null],
        ];
    }

    private function squad(string $team, array $players): array
    {
        return [
            'entity_type' => 'squad', 'external_id' => $team,
            'data' => ['team_external_id' => $team, 'players' => array_map(fn ($p) => ['player_external_id' => $p[0], 'position' => $p[1] ?? null], $players)],
        ];
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    private function open(string $team): array
    {
        return SquadMembership::where('team_id', $this->team($team)->id)->whereNull('left_on')->pluck('position', 'player_id')->all();
    }

    public function test_squad_creates_players_and_open_memberships_with_positions(): void
    {
        $this->seedTeams();

        $run = $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlGk0001', 'Denis Adamov', 'TeAm0001', 'GK'),
            $this->player('PlFw0001', 'Pedrinho', 'TeAm0001', 'FW', false),
            $this->player('PlNo0001', 'No Position', 'TeAm0001', null, false),
            $this->squad('TeAm0001', [['PlGk0001', 'GK'], ['PlFw0001', 'FW'], ['PlNo0001', null]]),
        ]);

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $this->assertSame(3, $run->stats['by_entity']['player']['created']);
        $this->assertSame(1, $run->stats['by_entity']['squad']['created']);
        $this->assertSame(3, $run->stats['by_entity']['squad_players']['created']);

        $zenit = $this->team('Zenit');
        $this->assertSame(3, SquadMembership::where('team_id', $zenit->id)->count());
        $this->assertSame(
            ['GK' => 1, 'FW' => 1, '' => 1],
            collect($this->open('Zenit'))->map(fn ($p) => $p?->value ?? '')->countBy()->all(),
        );
        $this->assertNull(SquadMembership::where('team_id', $zenit->id)->first()->joined_on);
        $this->assertSame('RU', Player::firstWhere('name', 'Pedrinho')->country->code, 'без профиля страна берётся у команды');
    }

    public function test_rerun_is_unchanged_and_squad_changes_close_and_reopen_memberships(): void
    {
        $this->seedTeams();
        $entities = [
            $this->player('PlGk0001', 'Denis Adamov', 'TeAm0001', 'GK'),
            $this->player('PlDf0001', 'Defender One', 'TeAm0001', 'DF'),
            $this->squad('TeAm0001', [['PlGk0001', 'GK'], ['PlDf0001', 'DF']]),
        ];
        $this->apply(IngestionKind::SyncSquads, $entities);

        $again = $this->apply(IngestionKind::SyncSquads, $entities);
        $this->assertSame(0, $again->stats['created'] + $again->stats['updated'], json_encode($again->stats['by_entity']));
        $this->assertSame(2, SquadMembership::count());

        $this->apply(IngestionKind::SyncSquads, [$this->squad('TeAm0001', [['PlGk0001', 'GK']])]);
        $defender = Player::firstWhere('name', 'Defender One');
        $this->assertSame([Player::firstWhere('name', 'Denis Adamov')->id], array_keys($this->open('Zenit')));
        $this->assertNotNull(SquadMembership::where('player_id', $defender->id)->value('left_on'));

        $this->apply(IngestionKind::SyncSquads, [$this->squad('TeAm0001', [['PlGk0001', 'GK'], ['PlDf0001', 'DF']])]);
        $this->assertCount(2, $this->open('Zenit'));
        $this->assertSame(3, SquadMembership::count(), 'вернувшийся игрок получает новую заявку');
        $this->assertNotNull(SquadMembership::where('player_id', $defender->id)->whereNull('left_on')->value('joined_on'));
    }

    public function test_transfer_closes_membership_in_the_previous_club(): void
    {
        $this->seedTeams();
        $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlMf0001', 'Midfielder', 'TeAm0001', 'MF'),
            $this->squad('TeAm0001', [['PlMf0001', 'MF']]),
        ]);

        $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlMf0001', 'Midfielder', 'TeAm0002', 'MF'),
            $this->squad('TeAm0002', [['PlMf0001', 'MF']]),
        ]);

        $this->assertSame([], $this->open('Zenit'));
        $this->assertCount(1, $this->open('Spartak Moscow'));
    }

    public function test_position_without_role_is_taken_from_known_player_and_not_wiped(): void
    {
        $this->seedTeams();
        $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlMf0001', 'Midfielder', 'TeAm0001', 'MF'),
            $this->squad('TeAm0001', [['PlMf0001', 'MF']]),
        ]);

        $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlMf0001', 'Midfielder', 'TeAm0001', null),
            $this->squad('TeAm0001', [['PlMf0001', null]]),
        ]);

        $this->assertSame(PlayerPosition::Midfielder, Player::firstWhere('name', 'Midfielder')->position);
        $this->assertSame(PlayerPosition::Midfielder, SquadMembership::first()->position);
    }

    public function test_unknown_team_and_unknown_players_are_reported_without_closing_anything(): void
    {
        $this->seedTeams();
        $this->apply(IngestionKind::SyncSquads, [
            $this->player('PlGk0001', 'Denis Adamov', 'TeAm0001', 'GK'),
            $this->squad('TeAm0001', [['PlGk0001', 'GK']]),
        ]);

        $run = $this->apply(IngestionKind::SyncSquads, [
            $this->squad('Nope0000', [['PlGk0001', 'GK']]),
            $this->squad('TeAm0001', [['PlGhost1', 'DF']]),
        ]);

        $this->assertSame(2, $run->stats['by_entity']['squad']['failed']);
        $this->assertCount(1, $this->open('Zenit'));
    }
}
