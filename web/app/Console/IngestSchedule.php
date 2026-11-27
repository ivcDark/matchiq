<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

/** Регистрация плановых ingest-задач; интервалы и окна — config/ingest.php (env INGEST_*). */
class IngestSchedule
{
    public static function register(Schedule $schedule): void
    {
        $config = config('ingest.schedule');
        if (! ($config['enabled'] ?? false)) {
            return;
        }

        self::job($schedule, 'fixtures', 'sync_fixtures: окно вокруг сегодняшней даты', $config['fixtures']['every_minutes'] ?? 0);
        self::job($schedule, 'details_missing', 'sync_match_details --missing: матчи без деталей', $config['details_missing']['every_minutes'] ?? 0);
        self::job($schedule, 'details_recent', 'sync_match_details: недавно завершённые матчи', $config['details_recent']['every_minutes'] ?? 0);

        self::daily($schedule, 'fixtures_full', 'sync_fixtures: весь текущий сезон', $config['fixtures_full']['daily_at'] ?? '');
        self::daily($schedule, 'catalog', 'sync_catalog: страны, лиги, сезоны', $config['catalog']['daily_at'] ?? '');
        self::daily($schedule, 'structure', 'sync_structure: стадии и команды сезона', $config['structure']['daily_at'] ?? '');

        $reap = self::cron((int) ($config['reap_stuck']['every_minutes'] ?? 0));
        if ($reap !== null) {
            $schedule->command('ingest:reap-stuck')->cron($reap)->withoutOverlapping(10)
                ->description('Закрыть зависшие прогоны ingest');
        }
    }

    /** Cron для «каждые N минут»; null — выключено. */
    public static function cron(int $minutes): ?string
    {
        if ($minutes < 1) {
            return null;
        }
        if ($minutes < 60) {
            return "*/{$minutes} * * * *";
        }

        $hours = min(24, intdiv($minutes, 60));

        return match (true) {
            $hours >= 24 => '0 0 * * *',
            $hours === 1 => '0 * * * *',
            default => "0 */{$hours} * * *",
        };
    }

    private static function job(Schedule $schedule, string $job, string $description, int $everyMinutes): void
    {
        $cron = self::cron($everyMinutes);
        if ($cron === null) {
            return;
        }

        $schedule->command('ingest:run-scheduled', [$job])->cron($cron)->withoutOverlapping(10)->description($description);
    }

    private static function daily(Schedule $schedule, string $job, string $description, string $at): void
    {
        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $at)) {
            return;
        }

        $schedule->command('ingest:run-scheduled', [$job])->dailyAt($at)->withoutOverlapping(30)->description($description);
    }
}
