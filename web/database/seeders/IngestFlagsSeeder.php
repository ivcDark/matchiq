<?php

namespace Database\Seeders;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Database\Seeder;

/**
 * Включает плановый ingest для РПЛ, ФНЛ и Кубка России. Остальные соревнования остаются выключенными
 * (`competitions.ingest_enabled` по умолчанию false). Для РПЛ и Кубка витринный `status` не трогаем;
 * ФНЛ (создаётся `sync_catalog` как `hidden`) дополнительно выводится в витрину как live-лига.
 */
class IngestFlagsSeeder extends Seeder
{
    public const ENABLED_SLUGS = ['premier-league', 'fnl', 'russian-cup'];

    /** Витринные атрибуты соревнований, которые `sync_catalog` создаёт «пустыми». */
    private const SHOWCASE = [
        'fnl' => [
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
            'tier' => 2,
            'sort_order' => 15,
            'short_name' => 'FNL',
            'short_name_i18n' => ['ru' => 'ФНЛ'],
            'name_i18n' => ['ru' => 'Первая лига'],
        ],
    ];

    public function run(): void
    {
        Competition::whereIn('slug', self::ENABLED_SLUGS)->update(['ingest_enabled' => true]);

        foreach (self::SHOWCASE as $slug => $attributes) {
            Competition::where('slug', $slug)->first()?->forceFill($attributes)->save();
        }
    }
}
