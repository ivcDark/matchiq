<?php

namespace App\Console\Commands;

use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Models\Season;
use App\Repositories\CompetitionRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\MatchRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\SourceProviderRepository;
use App\Repositories\StageRepository;
use App\Repositories\TeamRepository;
use App\Services\Ingestion\Exceptions\IngestionSkipped;
use App\Services\Ingestion\StartIngestionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class IngestDispatch extends Command
{
    private const DEFAULT_DETAILS_LIMIT = 50;

    private const MAX_DETAILS_LIMIT = 500;

    private const MAX_SQUAD_TEAMS = 200;

    protected $signature = 'ingest:dispatch
        {type : sync_catalog | sync_structure | sync_fixtures | sync_match_details | sync_squads}
        {--country= : sync_catalog: страна (название, alias или ISO-код)}
        {--league= : sync_catalog: часть названия лиги}
        {--league-id=* : sync_catalog: id лиг SStats (числовой или flash-id)}
        {--competition= : sync_structure, sync_fixtures: slug или id соревнования}
        {--season= : sync_structure, sync_fixtures: slug сезона (по умолчанию текущий)}
        {--stage= : sync_fixtures, sync_match_details: id или название стадии сезона (по умолчанию все стадии)}
        {--from= : sync_fixtures, sync_match_details: начало окна дат YYYY-MM-DD, включительно}
        {--to= : sync_fixtures, sync_match_details: конец окна дат YYYY-MM-DD, включительно}
        {--match=* : sync_match_details: flash-id или числовой id SStats матча (можно несколько); вместо выборки по сезону}
        {--limit= : sync_match_details: сколько завершённых матчей взять (по умолчанию 50, максимум 500), свежие первыми}
        {--team=* : sync_squads: slug, числовой id SStats или flash-id команды (можно несколько); по умолчанию все команды сезона соревнования}
        {--missing : sync_match_details: только матчи, по которым детали ещё не загружены}
        {--live : sync_match_details: только идущие сейчас матчи (status = live) вместо завершённых}
        {--scheduled : вызов из расписания: stats.context.trigger = schedule, «уже идёт» и «нечего грузить» — не ошибка}';

    protected $description = 'Создать прогон ingest и положить команду в RabbitMQ (ingest.commands)';

    public function handle(
        StartIngestionRun $start,
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        ExternalIdRepository $externalIds,
        StageRepository $stages,
        MatchRepository $matches,
        TeamRepository $teams,
    ): int {
        $kind = IngestionKind::tryFrom((string) $this->argument('type'));

        try {
            [$payload, $competitionId] = match ($kind) {
                IngestionKind::SyncCatalog => [$this->catalogPayload(), null],
                IngestionKind::SyncStructure => $this->structurePayload($providers, $competitions, $seasons, $externalIds),
                IngestionKind::SyncFixtures => $this->fixturesPayload($providers, $competitions, $seasons, $stages, $externalIds),
                IngestionKind::SyncMatchDetails => $this->matchDetailsPayload($providers, $competitions, $seasons, $stages, $matches, $externalIds),
                IngestionKind::SyncSquads => $this->squadsPayload($providers, $competitions, $seasons, $teams, $externalIds),
                default => throw new InvalidArgumentException('Поддерживаются типы: sync_catalog, sync_structure, sync_fixtures, sync_match_details, sync_squads.'),
            };

            $run = $start->handle($kind, $payload, $competitionId, $this->option('scheduled') ? ['trigger' => 'schedule'] : []);
        } catch (IngestionSkipped $e) {
            if ($this->option('scheduled')) {
                $this->warn('Пропущено: '.$e->getMessage());

                return self::SUCCESS;
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Команда не отправлена: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Прогон #{$run->id} ({$kind->value}) создан, команда опубликована.");

        return self::SUCCESS;
    }

    private function catalogPayload(): array
    {
        $filters = [
            'country' => (string) $this->option('country'),
            'league_name' => (string) $this->option('league'),
            'league_ids' => array_values(array_filter((array) $this->option('league-id'))),
        ];

        if ($filters['country'] === '' && $filters['league_name'] === '' && $filters['league_ids'] === []) {
            throw new InvalidArgumentException('sync_catalog требует хотя бы один фильтр: --country, --league или --league-id.');
        }

        return ['filters' => $filters];
    }

    /** @return array{0: array, 1: int} */
    private function structurePayload(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        ExternalIdRepository $externalIds,
    ): array {
        [$competition, $ids, $season] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_structure');
        if ($ids['season'] === null && $ids['competition'] === null) {
            throw new InvalidArgumentException('Структура сезона есть только в Ls-эндпоинтах SStats, а у соревнования нет flash-id.');
        }

        return [array_filter([
            'competition' => ['id' => $competition->id, 'external_id' => $ids['competition'] ?? ''],
            'season_external_id' => $ids['season'],
            'season_label' => $ids['season'] === null ? $season->label : null,
        ], fn ($v) => $v !== null), $competition->id];
    }

    /** @return array{0: array, 1: int} */
    private function fixturesPayload(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        StageRepository $stages,
        ExternalIdRepository $externalIds,
    ): array {
        [$competition, $ids, $season] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_fixtures');

        $year = preg_match('/^(\d{4})/', $season->label, $m) === 1 ? (int) $m[1] : null;
        if ($ids['season_api'] === null && ($ids['competition_api'] === null || $year === null)) {
            throw new InvalidArgumentException('У сезона нет uid SStats, а у соревнования — числового id: сначала выполните sync_catalog.');
        }

        $payload = array_filter([
            'competition' => ['id' => $competition->id, 'external_id' => $ids['competition'] ?? $ids['competition_api']],
            'season_api_id' => $ids['season_api'],
            'league_api_id' => $ids['season_api'] === null ? $ids['competition_api'] : null,
            'season_year' => $ids['season_api'] === null ? $year : null,
            'season_external_id' => $ids['season'],
            // Этап и тур /Games/list не отдаёт: у многоэтапного сезона их даёт запасной /Ls/List.
            'ls_stages' => $ids['season'] !== null && $stages->countInSeason($season->id) > 1 ? true : null,
        ], fn ($v) => $v !== null);

        $stageRef = (string) $this->option('stage');
        if ($stageRef !== '') {
            $stage = $stages->findInSeason($season->id, $stageRef)
                ?? throw new InvalidArgumentException("Стадия {$stageRef} в сезоне {$season->slug} не найдена: сначала выполните sync_structure.");
            $provider = $providers->ensure(SourceProviderCode::Sstats);
            $payload['stage_external_id'] = $externalIds->externalIdFor($provider->id, ExternalEntityType::Stage, $stage->id)
                ?? throw new InvalidArgumentException('У стадии нет внешнего id SStats.');
            unset($payload['ls_stages']);
        }

        $window = array_filter([
            'from' => $this->dateOption('from'),
            'to' => $this->dateOption('to'),
        ]);
        if (isset($window['from'], $window['to']) && $window['from'] > $window['to']) {
            throw new InvalidArgumentException('--from не может быть позже --to.');
        }
        if ($window !== []) {
            $payload['window'] = $window;
        }

        return [$payload, $competition->id];
    }

    /**
     * Матчи берёт Laravel: либо явные `--match`, либо завершённые матчи сезона по фильтрам. В collector уходят внешние id.
     *
     * @return array{0: array, 1: int}
     */
    private function matchDetailsPayload(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        StageRepository $stages,
        MatchRepository $matches,
        ExternalIdRepository $externalIds,
    ): array {
        $provider = $providers->ensure(SourceProviderCode::Sstats);
        $apiProvider = $providers->ensure(SourceProviderCode::SstatsApi);
        $explicit = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('match')))));

        if (count($explicit) > self::MAX_DETAILS_LIMIT) {
            throw new InvalidArgumentException('--match: не более '.self::MAX_DETAILS_LIMIT.' матчей за прогон.');
        }

        if ($explicit !== []) {
            $found = $externalIds->entityIdsFor($provider->id, ExternalEntityType::Match, $explicit)
                + $externalIds->entityIdsFor($apiProvider->id, ExternalEntityType::Match, $explicit);
            $unknown = array_values(array_diff($explicit, array_map('strval', array_keys($found))));
            if ($unknown !== []) {
                throw new InvalidArgumentException('Матчи не найдены (сначала sync_fixtures): '.implode(', ', $unknown));
            }

            $rows = $matches->findMany(array_values($found));
            $matchCompetitions = $rows->pluck('competition_id')->unique();
            if ($matchCompetitions->count() !== 1) {
                throw new InvalidArgumentException('Матчи из --match должны относиться к одному соревнованию.');
            }
            $competition = $competitions->find((int) $matchCompetitions->first());
            $this->assertCompetitionOption($competitions, $competition?->id);
            $season = $rows->pluck('season_id')->unique()->count() === 1 ? $seasons->find((int) $rows->first()->season_id) : null;

            $matchIds = array_values(array_unique($found));
        } else {
            $limit = $this->limitOption();
            [$competition, , $season] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_match_details');

            $stageId = null;
            $stageRef = (string) $this->option('stage');
            if ($stageRef !== '') {
                $stage = $stages->findInSeason($season->id, $stageRef)
                    ?? throw new InvalidArgumentException("Стадия {$stageRef} в сезоне {$season->slug} не найдена: сначала выполните sync_structure.");
                $stageId = $stage->id;
            }

            [$from, $to] = [$this->dateOption('from'), $this->dateOption('to')];
            if ($from !== null && $to !== null && $from > $to) {
                throw new InvalidArgumentException('--from не может быть позже --to.');
            }

            if ($this->option('live')) {
                if ($this->option('missing') || $from !== null || $to !== null) {
                    throw new InvalidArgumentException('--live несовместим с --missing, --from и --to.');
                }
                $maxAgeHours = max(1, (int) config('ingest.schedule.details_live.max_age_hours'));
                $rows = $matches->selectLiveForDetails($season->id, $stageId, now()->subHours($maxAgeHours), $limit);
            } else {
                $rows = $matches->selectFinishedForDetails($season->id, $stageId, $from, $to, (bool) $this->option('missing'), $limit);
            }
            $matchIds = $rows->pluck('id')->all();
        }

        // Ключ матча в collector — flash-id, у матча без него — числовой id SStats; /Games/{id} вызывается по числовому.
        $flashIds = $externalIds->externalIdsFor($provider->id, ExternalEntityType::Match, $matchIds);
        $apiIds = $externalIds->externalIdsFor($apiProvider->id, ExternalEntityType::Match, $matchIds);
        $externalMatchIds = [];
        $matchApiIds = [];
        foreach ($matchIds as $id) {
            $key = $flashIds[$id] ?? $apiIds[$id] ?? null;
            if ($key === null) {
                continue;
            }
            $externalMatchIds[] = $key;
            if (isset($flashIds[$id], $apiIds[$id])) {
                $matchApiIds[$key] = $apiIds[$id];
            }
        }
        if ($externalMatchIds === []) {
            throw new IngestionSkipped($this->option('live')
                ? 'Нет идущих матчей с id SStats.'
                : 'Нет подходящих завершённых матчей с id SStats (сначала sync_fixtures).');
        }

        $competitionExternalId = $externalIds->externalIdFor($provider->id, ExternalEntityType::Competition, $competition->id)
            ?? $externalIds->externalIdFor($apiProvider->id, ExternalEntityType::Competition, $competition->id)
            ?? throw new InvalidArgumentException('У соревнования нет id SStats.');
        $seasonExternalId = $season !== null
            ? ($externalIds->externalIdFor($provider->id, ExternalEntityType::Season, $season->id) ?? $externalIds->externalIdFor($apiProvider->id, ExternalEntityType::Season, $season->id))
            : null;

        $this->line('Матчей в прогоне: '.count($externalMatchIds));

        return [array_filter([
            'competition' => ['id' => $competition->id, 'external_id' => $competitionExternalId],
            'season_external_id' => $seasonExternalId,
            'match_external_ids' => $externalMatchIds,
            'match_api_ids' => $matchApiIds === [] ? null : $matchApiIds,
        ], fn ($v) => $v !== null), $competition->id];
    }

    /**
     * Составы клубов: команды выбирает Laravel (команды текущего сезона соревнования с `teams.ingest_enabled` либо явные `--team`),
     * в collector уходят числовые id команд SStats (/Players/list) и их flash-id (позиции из /Ls/Players).
     *
     * @return array{0: array, 1: int}
     */
    private function squadsPayload(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        TeamRepository $teams,
        ExternalIdRepository $externalIds,
    ): array {
        [$competition, $ids, $season] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_squads');
        $provider = $providers->ensure(SourceProviderCode::Sstats);
        $apiProvider = $providers->ensure(SourceProviderCode::SstatsApi);

        $explicit = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('team')))));
        if ($explicit !== []) {
            $teamIds = [];
            foreach ($explicit as $ref) {
                $teamIds[] = $teams->findBySlug($ref)?->id
                    ?? $externalIds->findEntityId($apiProvider->id, ExternalEntityType::Team, $ref)
                    ?? $externalIds->findEntityId($provider->id, ExternalEntityType::Team, $ref)
                    ?? throw new InvalidArgumentException("Команда {$ref} не найдена (slug, числовой id SStats или flash-id).");
            }
            $apiIds = $externalIds->externalIdsFor($apiProvider->id, ExternalEntityType::Team, $teamIds);
            foreach ($explicit as $i => $ref) {
                if (! isset($apiIds[$teamIds[$i]])) {
                    throw new InvalidArgumentException("У команды {$ref} нет числового id SStats (сначала sync_fixtures).");
                }
            }
        } else {
            $teamIds = $teams->idsInSeason($season->id, onlyIngestEnabled: true);
            $apiIds = $externalIds->externalIdsFor($apiProvider->id, ExternalEntityType::Team, $teamIds);
        }

        $flashIds = $externalIds->externalIdsFor($provider->id, ExternalEntityType::Team, $teamIds);
        $externalTeamIds = [];
        $teamFlashIds = [];
        foreach (array_unique($teamIds) as $id) {
            if (! isset($apiIds[$id])) {
                continue;
            }
            $externalTeamIds[] = $apiIds[$id];
            if (isset($flashIds[$id])) {
                $teamFlashIds[$apiIds[$id]] = $flashIds[$id];
            }
        }

        if ($externalTeamIds === []) {
            throw new IngestionSkipped('Нет команд сезона с числовым id SStats (сначала sync_fixtures).');
        }
        if (count($externalTeamIds) > self::MAX_SQUAD_TEAMS) {
            throw new InvalidArgumentException('sync_squads: не более '.self::MAX_SQUAD_TEAMS.' команд за прогон, выберите команды через --team.');
        }

        $this->line('Команд в прогоне: '.count($externalTeamIds));

        return [array_filter([
            'competition' => ['id' => $competition->id, 'external_id' => $ids['competition'] ?? $ids['competition_api']],
            'season_external_id' => $ids['season'] ?? $ids['season_api'],
            'team_external_ids' => $externalTeamIds,
            'team_flash_ids' => $teamFlashIds === [] ? null : $teamFlashIds,
        ], fn ($v) => $v !== null), $competition->id];
    }

    private function assertCompetitionOption(CompetitionRepository $competitions, ?int $matchCompetitionId): void
    {
        $ref = (string) $this->option('competition');
        if ($ref === '') {
            return;
        }

        $given = $competitions->findByIdOrSlug($ref);
        if ($given === null || $given->id !== $matchCompetitionId) {
            throw new InvalidArgumentException('--competition не совпадает с соревнованием матчей из --match.');
        }
    }

    private function limitOption(): int
    {
        $raw = (string) $this->option('limit');
        if ($raw === '') {
            return self::DEFAULT_DETAILS_LIMIT;
        }
        if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > self::MAX_DETAILS_LIMIT) {
            throw new InvalidArgumentException('--limit: ожидается число от 1 до '.self::MAX_DETAILS_LIMIT.'.');
        }

        return (int) $raw;
    }

    private function dateOption(string $name): ?string
    {
        $value = (string) $this->option($name);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$name}: ожидается дата YYYY-MM-DD, получено «{$value}».");
        }

        return $value;
    }

    /**
     * Соревнование, сезон и их id SStats: `competition`/`season` — Ls-ключи (провайдер `sstats`, flash-id лиги и id Ls-сезона),
     * `competition_api`/`season_api` — числовой id лиги и uid сезона основного API (`sstats_api`). Отсутствующий — null.
     *
     * @return array{0: Competition, 1: array{competition: ?string, season: ?string, competition_api: ?string, season_api: ?string}, 2: Season}
     */
    private function resolveTarget(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        ExternalIdRepository $externalIds,
        string $type,
    ): array {
        $ref = (string) $this->option('competition');
        if ($ref === '') {
            throw new InvalidArgumentException("{$type} требует --competition=<slug|id>.");
        }

        $competition = $competitions->findByIdOrSlug($ref)
            ?? throw new InvalidArgumentException("Соревнование {$ref} не найдено: сначала выполните sync_catalog.");

        $provider = $providers->ensure(SourceProviderCode::Sstats);
        $apiProvider = $providers->ensure(SourceProviderCode::SstatsApi);
        $competitionExternalId = $externalIds->externalIdFor($provider->id, ExternalEntityType::Competition, $competition->id);
        $competitionApiId = $externalIds->externalIdFor($apiProvider->id, ExternalEntityType::Competition, $competition->id);
        if ($competitionExternalId === null && $competitionApiId === null) {
            throw new InvalidArgumentException('У соревнования нет id SStats: сначала выполните sync_catalog.');
        }

        $seasonSlug = (string) $this->option('season');
        $season = $seasonSlug !== ''
            ? $seasons->findBySlug($competition->id, $seasonSlug)
            : $seasons->findCurrent($competition->id);
        if ($season === null) {
            throw new InvalidArgumentException('Сезон не найден (укажите --season=<slug> или отметьте текущий через sync_catalog).');
        }

        return [$competition, [
            'competition' => $competitionExternalId,
            'season' => $externalIds->externalIdFor($provider->id, ExternalEntityType::Season, $season->id),
            'competition_api' => $competitionApiId,
            'season_api' => $externalIds->externalIdFor($apiProvider->id, ExternalEntityType::Season, $season->id),
        ], $season];
    }
}
