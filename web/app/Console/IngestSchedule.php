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

        self::job($schedule, 'details_live', 'sync_match_details --live: идущие сейчас матчи', $config['details_live']['every_minutes'] ?? 0, (int) ($config['details_live']['offset_minutes'] ?? 0));

        self::daily($schedule, 'fixtures_full', 'sync_fixtures: весь текущий сезон', $config['fixtures_full']['daily_at'] ?? '');
        self::daily($schedule, 'catalog', 'sync_catalog: страны, лиги, сезоны', $config['catalog']['daily_at'] ?? '');
        self::daily($schedule, 'structure', 'sync_structure: стадии и команды сезона', $config['structure']['daily_at'] ?? '');

        self::daily($schedule, 'squads', 'sync_squads: составы клубов', $config['squads']['daily_at'] ?? '');

        $reap = self::cron((int) ($config['reap_stuck']['every_minutes'] ?? 0));
        if ($reap !== null) {
            $schedule->command('ingest:reap-stuck')->cron($reap)->timezone(config('app.timezone'))->withoutOverlapping(10)
                ->description('Закрыть зависшие прогоны ingest');
        }
    }

    /**
     * Cron для «каждые N минут»; null — выключено. `$offset` сдвигает старт внутри часа (только для N < 60):
     * нужен, чтобы задачи одного `kind` не стартовали в одну минуту — второй плановый прогон по соревнованию пропускается.
     */
    public static function cron(int $minutes, int $offset = 0): ?string
    {
        if ($minutes < 1) {
            return null;
        }
        if ($minutes < 60) {
            $offset = max(0, $offset) % $minutes;

            return $offset === 0 ? "*/{$minutes} * * * *" : "{$offset}-59/{$minutes} * * * *";
        }

        $hours = min(24, intdiv($minutes, 60));

        return match (true) {
            $hours >= 24 => '0 0 * * *',
            $hours === 1 => '0 * * * *',
            default => "0 */{$hours} * * *",
        };
    }

    private static function job(Schedule $schedule, string $job, string $description, int $everyMinutes, int $offset = 0): void
    {
        $cron = self::cron($everyMinutes, $offset);
        if ($cron === null) {
            return;
        }

        $schedule->command('ingest:run-scheduled', [$job])->cron($cron)->timezone(config('app.timezone'))->withoutOverlapping(10)->description($description);
    }

    private static function daily(Schedule $schedule, string $job, string $description, string $at): void
    {
        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $at)) {
            return;
        }

        $schedule->command('ingest:run-scheduled', [$job])->dailyAt($at)->timezone(config('app.timezone'))->withoutOverlapping(30)->description($description);
    }
}
