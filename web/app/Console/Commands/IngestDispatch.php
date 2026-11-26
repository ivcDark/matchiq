<?php

namespace App\Console\Commands;

use App\Enums\ExternalEntityType;
use App\Enums\IngestionKind;
use App\Enums\SourceProviderCode;
use App\Repositories\CompetitionRepository;
use App\Repositories\ExternalIdRepository;
use App\Repositories\SeasonRepository;
use App\Repositories\SourceProviderRepository;
use App\Services\Ingestion\StartIngestionRun;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class IngestDispatch extends Command
{
    protected $signature = 'ingest:dispatch
        {type : sync_catalog | sync_structure}
        {--country= : sync_catalog: страна (название, alias или ISO-код)}
        {--league= : sync_catalog: часть названия лиги}
        {--league-id=* : sync_catalog: внешние id лиг SStats}
        {--competition= : sync_structure: slug или id соревнования}
        {--season= : sync_structure: slug сезона (по умолчанию текущий)}';

    protected $description = 'Создать прогон ingest и положить команду в RabbitMQ (ingest.commands)';

    public function handle(
        StartIngestionRun $start,
        SourceProviderRepository $providers,
        CompetitionRepository $competitions,
        SeasonRepository $seasons,
        ExternalIdRepository $externalIds,
    ): int {
        $kind = IngestionKind::tryFrom((string) $this->argument('type'));

        try {
            [$payload, $competitionId] = match ($kind) {
                IngestionKind::SyncCatalog => [$this->catalogPayload(), null],
                IngestionKind::SyncStructure => $this->structurePayload($providers, $competitions, $seasons, $externalIds),
                default => throw new InvalidArgumentException('Поддерживаются типы: sync_catalog, sync_structure.'),
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
        $ref = (string) $this->option('competition');
        if ($ref === '') {
            throw new InvalidArgumentException('sync_structure требует --competition=<slug|id>.');
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

        return [[
            'competition' => ['id' => $competition->id, 'external_id' => $competitionExternalId],
            'season_external_id' => $seasonExternalId,
        ], $competition->id];
    }
}
