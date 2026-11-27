<?php

namespace App\Console\Commands;

use App\Enums\SourceProviderCode;
use App\Models\Competition;
use App\Repositories\CompetitionRepository;
use App\Repositories\SourceProviderRepository;
use Illuminate\Console\Command;

/**
 * Точка входа расписания: соревнования определяются в момент запуска (а не при регистрации расписания),
 * дальше работает обычный `ingest:dispatch --scheduled`.
 */
class IngestRunScheduled extends Command
{
    public const JOBS = ['fixtures', 'fixtures_full', 'details_missing', 'details_recent', 'catalog', 'structure', 'squads'];

    protected $signature = 'ingest:run-scheduled {job : fixtures | fixtures_full | details_missing | details_recent | catalog | structure | squads}';

    protected $description = 'Поставить в очередь плановые прогоны ingest для включённых соревнований (вызывается из scheduler)';

    public function handle(SourceProviderRepository $providers, CompetitionRepository $competitions): int
    {
        $job = (string) $this->argument('job');
        if (! in_array($job, self::JOBS, true)) {
            $this->error('Неизвестная задача «'.$job.'». Доступно: '.implode(', ', self::JOBS).'.');

            return self::FAILURE;
        }

        if ($job === 'catalog') {
            return $this->dispatchCatalog();
        }

        $provider = $providers->ensure(SourceProviderCode::Sstats);
        $slugs = $job === 'squads'
            ? config('ingest.schedule.squads.competitions', [])
            : config('ingest.schedule.competitions', []);
        $targets = $competitions->forScheduledIngest($provider->id, $slugs);
        if ($targets->isEmpty()) {
            $this->warn('Нет соревнований для ingest (status = live с внешним id SStats или INGEST_COMPETITIONS).');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($targets as $competition) {
            $failed += $this->call('ingest:dispatch', $this->dispatchArguments($job, $competition)) === self::SUCCESS ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dispatchCatalog(): int
    {
        $country = (string) config('ingest.schedule.catalog.country');
        $league = (string) config('ingest.schedule.catalog.league');
        if ($country === '' && $league === '') {
            $this->warn('sync_catalog пропущен: не заданы INGEST_CATALOG_COUNTRY / INGEST_CATALOG_LEAGUE.');

            return self::SUCCESS;
        }

        return $this->call('ingest:dispatch', array_filter([
            'type' => 'sync_catalog',
            '--country' => $country,
            '--league' => $league,
            '--scheduled' => true,
        ]));
    }

    /** @return array<string, mixed> */
    private function dispatchArguments(string $job, Competition $competition): array
    {
        $today = now(config('app.timezone'))->startOfDay();
        $base = ['--competition' => $competition->slug, '--scheduled' => true];

        return match ($job) {
            'fixtures' => $base + [
                'type' => 'sync_fixtures',
                '--from' => $today->copy()->subDays(max(0, (int) config('ingest.schedule.fixtures.days_back')))->toDateString(),
                '--to' => $today->copy()->addDays(max(0, (int) config('ingest.schedule.fixtures.days_forward')))->toDateString(),
            ],
            'fixtures_full' => $base + ['type' => 'sync_fixtures'],
            'details_missing' => $base + [
                'type' => 'sync_match_details',
                '--missing' => true,
                '--limit' => (string) max(1, (int) config('ingest.schedule.details_missing.limit')),
            ],
            'details_recent' => $base + [
                'type' => 'sync_match_details',
                '--from' => $today->copy()->subDays(max(0, (int) config('ingest.schedule.details_recent.days_back')))->toDateString(),
                '--to' => $today->toDateString(),
                '--limit' => (string) max(1, (int) config('ingest.schedule.details_recent.limit')),
            ],
            'structure' => $base + ['type' => 'sync_structure'],
            'squads' => $base + ['type' => 'sync_squads'],
        };
    }
}
