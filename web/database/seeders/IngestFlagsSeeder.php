<?php

namespace Database\Seeders;

use App\Models\Competition;
use Illuminate\Database\Seeder;

/**
 * Включает плановый ingest для РПЛ и Кубка России. Остальные соревнования остаются выключенными
 * (`competitions.ingest_enabled` по умолчанию false); витринный `status` не трогаем.
 */
class IngestFlagsSeeder extends Seeder
{
    public const ENABLED_SLUGS = ['premier-league', 'russian-cup'];

    public function run(): void
    {
        Competition::whereIn('slug', self::ENABLED_SLUGS)->update(['ingest_enabled' => true]);
    }
}
