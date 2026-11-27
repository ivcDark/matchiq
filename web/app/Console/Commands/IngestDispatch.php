<?php

namespace App\Console\Commands;

use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Models\Season;
use App\Repositories\CompetitionRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\SourceProviderRepository;
use App\Repositories\StageRepository;
use App\Services\Ingestion\StartIngestionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class IngestDispatch extends Command
{
    protected $signature = 'ingest:dispatch
        {type : sync_catalog | sync_structure | sync_fixtures}
        {--country= : sync_catalog: страна (название, alias или ISO-код)}
        {--league= : sync_catalog: часть названия лиги}
        {--league-id=* : sync_catalog: внешние id лиг SStats}
        {--competition= : sync_structure, sync_fixtures: slug или id соревнования}
        {--season= : sync_structure, sync_fixtures: slug сезона (по умолчанию текущий)}
        {--stage= : sync_fixtures: id или название стадии сезона (по умолчанию все стадии)}
        {--from= : sync_fixtures: начало окна дат YYYY-MM-DD, включительно}
        {--to= : sync_fixtures: конец окна дат YYYY-MM-DD, включительно}';

    protected $description = 'Создать прогон ingest и положить команду в RabbitMQ (ingest.commands)';

    public function handle(
        StartIngestionRun $start,
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        ExternalIdRepository $externalIds,
        StageRepository $stages,
    ): int {
        $kind = IngestionKind::tryFrom((string) $this->argument('type'));

        try {
            [$payload, $competitionId] = match ($kind) {
                IngestionKind::SyncCatalog => [$this->catalogPayload(), null],
                IngestionKind::SyncStructure => $this->structurePayload($providers, $competitions, $seasons, $externalIds),
                IngestionKind::SyncFixtures => $this->fixturesPayload($providers, $competitions, $seasons, $stages, $externalIds),
                default => throw new InvalidArgumentException('Поддерживаются типы: sync_catalog, sync_structure, sync_fixtures.'),
            };

            $run = $start->handle($kind, $payload, $competitionId);
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
        [$competition, $competitionExternalId, $season, $seasonExternalId] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_structure');

        return [[
            'competition' => ['id' => $competition->id, 'external_id' => $competitionExternalId],
            'season_external_id' => $seasonExternalId,
        ], $competition->id];
    }

    /** @return array{0: array, 1: int} */
    private function fixturesPayload(
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        StageRepository $stages,
        ExternalIdRepository $externalIds,
    ): array {
        [$competition, $competitionExternalId, $season, $seasonExternalId] = $this->resolveTarget($providers, $competitions, $seasons, $externalIds, 'sync_fixtures');

        $payload = [
            'competition' => ['id' => $competition->id, 'external_id' => $competitionExternalId],
            'season_external_id' => $seasonExternalId,
        ];

        $stageRef = (string) $this->option('stage');
        if ($stageRef !== '') {
            $stage = $stages->findInSeason($season->id, $stageRef)
                ?? throw new InvalidArgumentException("Стадия {$stageRef} в сезоне {$season->slug} не найдена: сначала выполните sync_structure.");
            $provider = $providers->ensure(SourceProviderCode::Sstats);
            $payload['stage_external_id'] = $externalIds->externalIdFor($provider->id, ExternalEntityType::Stage, $stage->id)
                ?? throw new InvalidArgumentException('У стадии нет внешнего id SStats.');
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

    /** @return array{0: Competition, 1: string, 2: Season, 3: string} */
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
        $competitionExternalId = $externalIds->externalIdFor($provider->id, ExternalEntityType::Competition, $competition->id)
            ?? throw new InvalidArgumentException('У соревнования нет внешнего id SStats.');

        $seasonSlug = (string) $this->option('season');
        $season = $seasonSlug !== ''
            ? $seasons->findBySlug($competition->id, $seasonSlug)
            : $seasons->findCurrent($competition->id);
        if ($season === null) {
            throw new InvalidArgumentException('Сезон не найден (укажите --season=<slug> или отметьте текущий через sync_catalog).');
        }

        $seasonExternalId = $externalIds->externalIdFor($provider->id, ExternalEntityType::Season, $season->id)
            ?? throw new InvalidArgumentException('У сезона нет внешнего id SStats.');

        return [$competition, $competitionExternalId, $season, $seasonExternalId];
    }
}
