<?php

namespace Tests\Feature\Ingestion;

use App\Data\Ingestion\ResultMessage;
use App\Enums\AbsenceType;
use App\Enums\CompetitionScope;
use App\Enums\CountryKind;
use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use App\Enums\PlayerPosition;
use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\IngestionRun;
use App\Models\MatchOdd;
use App\Models\Player;
use App\Models\PlayerAbsence;
use App\Models\Season;
use App\Models\SquadMembership;
use App\Models\Team;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\ApplyIngestionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ingest из основного API SStats: /Leagues, /Games/list, /Players/list; ключ — числовые id (провайдер sstats_api). */
class MainApiIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(IngestionKind $kind, array $entities): IngestionRun
    {
        $provider = app(SourceProviderRepository::class)->ensure(SourceProviderCode::Sstats);
        $run = IngestionRun::create([
            'source_provider_id' => $provider->id, 'kind' => $kind, 'status' => IngestionStatus::Running, 'started_at' => now(),
        ]);
        $apply = app(ApplyIngestionResult::class);
        $base = ['schema_version' => 1, 'message_id' => 'm', 'run_id' => $run->id, 'job_type' => $kind->value, 'provider' => 'sstats'];
        $apply->handle(ResultMessage::fromArray(['kind' => 'batch', 'seq' => 1, 'entities' => $entities] + $base));
        $apply->handle(ResultMessage::fromArray(['kind' => 'done', 'batches_total' => 1, 'source' => ['requests' => 1, 'rate_limited' => 0]] + $base));

        return $run->refresh();
    }

    private function entityOf(SourceProviderCode $code, ExternalEntityType $type, string $externalId): ?int
    {
        $provider = app(SourceProviderRepository::class)->ensure($code);

        return app(ExternalIdRepository::class)->findEntityId($provider->id, $type, $externalId);
    }

    private function catalog(array $extra = []): IngestionRun
    {
        return $this->ingest(IngestionKind::SyncCatalog, array_merge([
            ['entity_type' => 'competition', 'external_id' => '236', 'data' => [
                'api_id' => '236', 'flash_id' => 'jizXHcsM', 'country_code' => 'RU', 'country_name' => 'Russia', 'name' => 'FNL',
                'type' => 'league', 'scope' => 'domestic', 'refs' => ['flashscore' => 'jizXHcsM', 'pari' => '80073'],
            ]],
            ['entity_type' => 'season', 'external_id' => 'uid-2025', 'data' => [
                'api_id' => 'uid-2025', 'competition_api_id' => '236', 'label' => '2025-2026', 'is_current' => false, 'starts_on' => '2025-07-12', 'ends_on' => '2026-05-30',
            ]],
            ['entity_type' => 'season', 'external_id' => 'uid-2026', 'data' => [
                'api_id' => 'uid-2026', 'competition_api_id' => '236', 'label' => '2026-2027', 'is_current' => true, 'starts_on' => '2026-07-11', 'ends_on' => '2027-05-29',
            ]],
        ], $extra));
    }

    private function structure(array $stages = [['StMain01', 'Regular Season', 'league', true]]): void
    {
        $entities = [
            ['entity_type' => 'season', 'external_id' => 'rXQUSf8L', 'data' => ['competition_external_id' => 'jizXHcsM', 'label' => '2026-2027']],
            ['entity_type' => 'team', 'external_id' => 'fPxiAl6T', 'data' => ['season_external_id' => 'rXQUSf8L', 'name' => 'Ufa']],
            ['entity_type' => 'team', 'external_id' => 'bkQFEaqI', 'data' => ['season_external_id' => 'rXQUSf8L', 'name' => 'Leningradets']],
        ];
        foreach ($stages as $i => [$id, $name, $type, $standings]) {
            $entities[] = ['entity_type' => 'stage', 'external_id' => $id, 'data' => [
                'season_external_id' => 'rXQUSf8L', 'name' => $name, 'type' => $type, 'stage_order' => $i + 1, 'has_standings' => $standings,
            ]];
        }
        $this->ingest(IngestionKind::SyncStructure, $entities);
    }

    private function teams(): array
    {
        return [
            ['entity_type' => 'team', 'external_id' => '1078', 'data' => ['api_id' => '1078', 'flash_id' => 'fPxiAl6T', 'name' => 'FC UFA', 'country_code' => 'RU', 'refs' => ['flashscore' => 'fPxiAl6T', 'pari' => '420730']]],
            ['entity_type' => 'team', 'external_id' => '6811', 'data' => ['api_id' => '6811', 'name' => 'Leningradets', 'country_code' => 'RU']],
        ];
    }

    private function game(string $id, array $data = []): array
    {
        return ['entity_type' => 'match', 'external_id' => $id, 'data' => $data + [
            'api_id' => $id, 'season_api_id' => 'uid-2026', 'season_external_id' => 'rXQUSf8L',
            'round_name' => 'Regular Season - 1', 'round_number' => 1,
            'home_team_api_id' => '1078', 'away_team_api_id' => '6811', 'home_team_external_id' => 'fPxiAl6T',
            'kickoff_at' => '2026-07-11T12:00:00Z', 'status' => 'finished', 'period' => null,
            'home_goals' => 3, 'away_goals' => 0, 'home_goals_ht' => 2, 'away_goals_ht' => 0,
        ]];
    }

    public function test_catalog_from_leagues_creates_competition_and_dated_seasons(): void
    {
        $run = $this->catalog([
            ['entity_type' => 'competition', 'external_id' => '2', 'data' => ['api_id' => '2', 'flash_id' => 'xGrwqq16', 'country_code' => 'WW', 'country_name' => 'World', 'name' => 'UEFA Champions League', 'type' => 'league', 'scope' => 'continental']],
        ]);

        $this->assertSame(IngestionStatus::Ok, $run->status);
        $fnl = Competition::findOrFail($this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Competition, '236'));
        $this->assertSame('FNL', $fnl->name);
        $this->assertSame('RU', $fnl->country->code);
        $this->assertSame($fnl->id, $this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Competition, 'jizXHcsM'));
        $this->assertSame($fnl->id, $this->entityOf(SourceProviderCode::Pari, ExternalEntityType::Competition, '80073'));

        $ucl = Competition::findOrFail($this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Competition, '2'));
        $this->assertSame(CompetitionScope::Continental, $ucl->scope);
        $this->assertSame(CountryKind::Region, Country::firstWhere('code', 'WW')->kind);

        $current = Season::findOrFail($this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Season, 'uid-2026'));
        $this->assertTrue($current->is_current);
        $this->assertSame('2026-07-11', $current->starts_on->format('Y-m-d'));
        $this->assertSame('2027-05-29', $current->ends_on->format('Y-m-d'));
        $this->assertFalse(Season::firstWhere('label', '2025-2026')->is_current);

        $again = $this->catalog();
        $this->assertSame(0, $again->stats['created'] + $again->stats['updated']);
    }

    public function test_catalog_updates_existing_competition_name_and_country_but_keeps_logo(): void
    {
        $this->ingest(IngestionKind::SyncCatalog, [
            ['entity_type' => 'country', 'external_id' => 'eur', 'data' => ['code' => 'EUR', 'name' => 'Europe', 'kind' => 'region']],
            ['entity_type' => 'competition', 'external_id' => 'xGrwqq16', 'data' => ['country_external_id' => 'eur', 'name' => 'Champions League', 'type' => 'league', 'scope' => 'continental', 'logo_url' => 'https://x/ucl.png']],
        ]);

        $this->ingest(IngestionKind::SyncCatalog, [
            ['entity_type' => 'competition', 'external_id' => '2', 'data' => ['api_id' => '2', 'flash_id' => 'xGrwqq16', 'country_code' => 'WW', 'country_name' => 'World', 'name' => 'UEFA Champions League', 'scope' => 'continental']],
        ]);

        $this->assertSame(1, Competition::count());
        $ucl = Competition::firstOrFail();
        $this->assertSame('UEFA Champions League', $ucl->name);
        $this->assertSame('WW', $ucl->country->code, 'страна из /Leagues перезаписывает EUR');
        $this->assertSame('https://x/ucl.png', $ucl->logo_url, 'логотипа в /Leagues нет — прежний остаётся');
        $this->assertSame('champions-league', $ucl->slug);
        $this->assertSame($ucl->id, $this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Competition, '2'));
    }

    public function test_structure_season_links_ls_id_without_touching_current_flag(): void
    {
        $this->catalog();
        $this->structure();

        $season = Season::firstWhere('label', '2026-2027');
        $this->assertTrue($season->is_current);
        $this->assertSame($season->id, $this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Season, 'rXQUSf8L'));
        $this->assertSame(2, Season::count());
    }

    public function test_games_list_links_teams_by_id_and_takes_the_only_stage(): void
    {
        $this->catalog();
        $this->structure();

        $run = $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [
            $this->game('1563072', ['flash_id' => 'xWXoFJwP', 'refs' => ['flashscore' => 'xWXoFJwP', 'pari' => '66244605'], 'odds' => [
                ['market_id' => 1, 'outcome' => 'Home', 'value' => 2.07],
                ['market_id' => 1, 'outcome' => 'Draw', 'value' => 3.1],
                ['market_id' => 5, 'market_name' => 'Goals Over/Under', 'outcome' => 'Over 2.5', 'value' => 2.17],
            ]]),
        ]));
        $this->assertSame(IngestionStatus::Ok, $run->status, json_encode($run->errors()->pluck('message')));

        $ufa = Team::findOrFail($this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Team, 'fPxiAl6T'));
        $this->assertSame('Ufa', $ufa->name, 'существующая команда не переименовывается');
        $this->assertSame($ufa->id, $this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Team, '1078'));
        $this->assertSame($ufa->id, $this->entityOf(SourceProviderCode::Pari, ExternalEntityType::Team, '420730'));

        $leningradets = $this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Team, '6811');
        $this->assertNotSame($leningradets, $this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Team, 'bkQFEaqI'), 'без flash-id команда опознаётся только по числовому id, не по имени');
        $this->assertSame(2, Team::where('name', 'Leningradets')->count());

        $match = FootballMatch::findOrFail($this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Match, '1563072'));
        $this->assertSame($match->id, $this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Match, 'xWXoFJwP'));
        $this->assertSame('Regular Season', $match->stage->name);
        $this->assertSame(1, $match->round->number);
        $this->assertSame(3, $match->home_goals);
        $this->assertSame($leningradets, $match->away_team_id);

        $this->assertSame(3, MatchOdd::where('match_id', $match->id)->count());
        $this->assertSame('2.070', MatchOdd::where('outcome', 'Home')->value('value'));

        $match->forceFill(['home_goals_et' => 1, 'away_goals_et' => 0, 'home_pens' => 4, 'away_pens' => 3])->save();
        $again = $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [
            $this->game('1563072', ['flash_id' => 'xWXoFJwP', 'odds' => [['market_id' => 1, 'outcome' => 'Home', 'value' => 2.2]]]),
        ]));
        $match->refresh();
        $this->assertSame([1, 0, 4, 3], [$match->home_goals_et, $match->away_goals_et, $match->home_pens, $match->away_pens], 'ET и пенальти /Games/list не отдаёт — не затираются');
        $this->assertSame(['2.200'], MatchOdd::where('match_id', $match->id)->pluck('value')->all(), 'линия синхронизируется целиком');
        $this->assertSame(IngestionStatus::Ok, $again->status);

        $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [
            $this->game('1563072', ['flash_id' => 'xWXoFJwP', 'home_pens' => 5, 'away_pens' => 4]),
        ]));
        $match->refresh();
        $this->assertSame([1, 0, 5, 4], [$match->home_goals_et, $match->away_goals_et, $match->home_pens, $match->away_pens], 'пенальти из /Ls/List (если он запрошен) обновляются');
    }

    public function test_games_list_falls_back_to_ls_season_when_uid_is_not_linked_yet(): void
    {
        $this->catalog();
        $this->structure();

        $run = $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [
            $this->game('77', ['season_api_id' => 'uid-unknown']),
            $this->game('78', ['season_api_id' => 'uid-unknown', 'season_external_id' => null, 'kickoff_at' => '2026-08-11T12:00:00Z']),
        ]));

        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(1, FootballMatch::count(), 'сезон найден по Ls-id из payload');
        $this->assertStringContainsString('uid-unknown', $run->errors()->firstOrFail()->message);
    }

    public function test_games_list_adopts_ls_match_by_flash_id(): void
    {
        $this->catalog();
        $this->structure();
        $this->ingest(IngestionKind::SyncFixtures, [[
            'entity_type' => 'match', 'external_id' => 'xWXoFJwP', 'data' => [
                'season_external_id' => 'rXQUSf8L', 'stage_external_id' => 'StMain01', 'round_number' => 1,
                'home_team_external_id' => 'fPxiAl6T', 'away_team_external_id' => 'bkQFEaqI',
                'kickoff_at' => '2026-07-11T12:00:00Z', 'status' => 'scheduled', 'period' => null, 'home_goals' => null, 'away_goals' => null,
            ],
        ]]);
        $this->assertSame(1, FootballMatch::count());

        $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [$this->game('1563072', ['flash_id' => 'xWXoFJwP'])]));

        $this->assertSame(1, FootballMatch::count(), 'матч из Ls найден по flash-id');
        $this->assertSame(3, FootballMatch::firstOrFail()->home_goals);
    }

    public function test_multi_stage_season_needs_ls_stage_or_known_match(): void
    {
        $this->catalog();
        $this->structure([['StMain01', 'Regular Season', 'league', true], ['StPlay01', 'Play-offs', 'knockout', false]]);

        $run = $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [
            $this->game('1'),
            $this->game('2', ['stage_external_id' => 'StPlay01', 'round_number' => null, 'round_name' => 'Final', 'kickoff_at' => '2027-05-20T12:00:00Z']),
        ]));

        $this->assertSame(IngestionStatus::Partial, $run->status);
        $this->assertSame(1, FootballMatch::count());
        $this->assertSame('Play-offs', FootballMatch::firstOrFail()->stage->name);
        $this->assertStringContainsString('этап матча не известен', $run->errors()->firstOrFail()->message);

        $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [$this->game('2', ['round_number' => null, 'round_name' => 'Final', 'kickoff_at' => '2027-05-20T12:00:00Z'])]));
        $this->assertSame('Play-offs', FootballMatch::firstOrFail()->stage->name, 'без этапа от Ls — этап уже известного матча');
    }

    public function test_squads_from_players_list_with_profiles_positions_and_absences(): void
    {
        $this->catalog();
        $this->structure();
        $this->ingest(IngestionKind::SyncFixtures, $this->teams());
        $ufa = $this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Team, '1078');
        $this->ingest(IngestionKind::SyncSquads, [
            ['entity_type' => 'player', 'external_id' => 'PlOld001', 'data' => ['name' => 'Kamilov Rustam', 'team_external_id' => 'fPxiAl6T', 'position' => 'MF', 'has_profile' => true]],
            ['entity_type' => 'squad', 'external_id' => 'fPxiAl6T', 'data' => ['players' => [['player_external_id' => 'PlOld001', 'position' => 'MF']]]],
        ]);

        $player = fn (string $id, array $data) => ['entity_type' => 'player', 'external_id' => $id, 'data' => $data + [
            'people_source' => 'sstats_api', 'team_external_id' => '1078', 'has_profile' => true,
        ]];
        $squad = fn (array $absences) => ['entity_type' => 'squad', 'external_id' => '1078', 'data' => ['people_source' => 'sstats_api', 'team_external_id' => '1078', 'players' => [
            ['player_external_id' => '826', 'position' => 'DF', 'absences' => $absences],
            ['player_external_id' => '900'],
            ['player_external_id' => '901'],
        ]]];

        $run = $this->ingest(IngestionKind::SyncSquads, [
            $player('826', ['name' => 'Igor Diveev', 'first_name' => 'Igor', 'last_name' => 'Diveev', 'nationality' => 'Russia', 'birth_date' => '1999-09-27', 'height_cm' => 194, 'photo_url' => 'https://sstats.net/p/826.png', 'flash_id' => 'FlDiv001', 'position' => 'DF']),
            $player('900', ['name' => 'Rustam Kamilov', 'first_name' => 'Rustam', 'last_name' => 'Kamilov', 'nationality' => 'Atlantis']),
            $player('901', ['name' => 'New Guy']),
            $squad([
                ['type' => 'injury', 'reason' => 'Knee Injury', 'since' => '2026-11-01'],
                ['type' => 'suspension', 'reason' => 'Red Card', 'since' => '2026-11-20', 'expected_return' => '2026-12-01'],
            ]),
        ]);
        $this->assertSame(IngestionStatus::Ok, $run->status, json_encode($run->errors()->pluck('message')));

        $diveev = Player::findOrFail($this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Player, '826'));
        $this->assertSame(['Igor Diveev', 'Igor', 'Diveev', 194, '1999-09-27', PlayerPosition::Defender, 'RU'], [
            $diveev->name, $diveev->first_name, $diveev->last_name, $diveev->height_cm, $diveev->birth_date->format('Y-m-d'), $diveev->position, $diveev->country->code,
        ]);
        $this->assertSame($diveev->id, $this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Player, 'FlDiv001'));

        $kamilov = $this->entityOf(SourceProviderCode::SstatsApi, ExternalEntityType::Player, '900');
        $this->assertSame($this->entityOf(SourceProviderCode::Sstats, ExternalEntityType::Player, 'PlOld001'), $kamilov, 'найден по имени среди игроков команды');
        $this->assertSame(PlayerPosition::Midfielder, Player::find($kamilov)->position, 'без позиции из Ls — прежняя');
        $this->assertSame(3, Player::count());
        $this->assertSame(3, SquadMembership::where('team_id', $ufa)->whereNull('left_on')->count());

        $active = PlayerAbsence::where('team_id', $ufa)->active()->orderBy('since')->get();
        $this->assertSame([AbsenceType::Injury, AbsenceType::Suspension], $active->pluck('type')->all());
        $this->assertSame('2026-12-01', $active[1]->expected_return->format('Y-m-d'));

        $this->ingest(IngestionKind::SyncSquads, [$squad([['type' => 'injury', 'reason' => 'Knee Injury', 'since' => '2026-11-01']])]);
        $this->assertSame(1, PlayerAbsence::active()->count(), 'дисквалификация, которой больше нет в источнике, закрыта');
        $this->assertSame(2, PlayerAbsence::count());
    }

    public function test_match_details_find_api_only_match_and_store_extra_time(): void
    {
        $this->catalog();
        $this->structure();
        $this->ingest(IngestionKind::SyncFixtures, array_merge($this->teams(), [$this->game('1397048', [
            'status' => 'live', 'home_goals' => 1, 'away_goals' => 1,
        ])]));

        $run = $this->ingest(IngestionKind::SyncMatchDetails, [[
            'entity_type' => 'match_details', 'external_id' => '1397048', 'data' => [
                'people_source' => 'sstats_api', 'teams' => [], 'lineup' => [], 'events' => [], 'team_stats' => [], 'player_stats' => [],
                'state' => ['status' => 'finished', 'home_goals' => 2, 'away_goals' => 1, 'home_goals_ht' => 1, 'away_goals_ht' => 0, 'home_goals_et' => 1, 'away_goals_et' => 0],
            ],
        ]]);

        $this->assertSame(IngestionStatus::Ok, $run->status, json_encode($run->errors()->pluck('message')));
        $match = FootballMatch::firstOrFail();
        $this->assertSame([2, 1, 1, 0], [$match->home_goals, $match->away_goals, $match->home_goals_et, $match->away_goals_et]);
    }
}
