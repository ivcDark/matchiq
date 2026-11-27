<?php

namespace Database\Seeders;

use App\Enums\CompetitionScope;
use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\StageType;
use App\Models\Competition;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Включает плановый ingest для РПЛ, ФНЛ, Кубка России, еврокубков (Лига чемпионов, Лига Европы, Лига конференций)
 * и топ-5 чемпионатов Европы без РПЛ (АПЛ, Ла Лига, Бундеслига, Серия A). Остальные соревнования остаются выключенными
 * (`competitions.ingest_enabled` по умолчанию false). Для РПЛ и Кубка витринный `status` не трогаем;
 * остальные (их создаёт `sync_catalog` как `hidden`) дополнительно выводятся в витрину как live-турниры.
 */
class IngestFlagsSeeder extends Seeder
{
    /** Slug'и, которые генерирует `sync_catalog`: у английской Премьер-лиги `premier-league` занят РПЛ. */
    public const ENABLED_SLUGS = [
        'premier-league',
        'fnl',
        'russian-cup',
        'champions-league',
        'europa-league',
        'conference-league',
        'premier-league-gb-eng',
        'laliga',
        'bundesliga',
        'serie-a',
    ];

    /** Еврокубки: у команд, игравших только квалификацию, составы не грузим (`teams.ingest_enabled = false`). */
    public const EUROCUP_SLUGS = ['champions-league', 'europa-league', 'conference-league'];

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
        'champions-league' => [
            'type' => CompetitionType::Tournament,
            'scope' => CompetitionScope::Continental,
            'status' => CompetitionStatus::Live,
            'sort_order' => 30,
            'short_name' => 'UCL',
            'short_name_i18n' => ['ru' => 'ЛЧ'],
            'name_i18n' => ['ru' => 'Лига чемпионов'],
        ],
        'europa-league' => [
            'type' => CompetitionType::Tournament,
            'scope' => CompetitionScope::Continental,
            'status' => CompetitionStatus::Live,
            'sort_order' => 40,
            'short_name' => 'UEL',
            'short_name_i18n' => ['ru' => 'ЛЕ'],
            'name_i18n' => ['ru' => 'Лига Европы'],
        ],
        'conference-league' => [
            'type' => CompetitionType::Tournament,
            'scope' => CompetitionScope::Continental,
            'status' => CompetitionStatus::Live,
            'sort_order' => 50,
            'short_name' => 'UECL',
            'short_name_i18n' => ['ru' => 'ЛК'],
            'name_i18n' => ['ru' => 'Лига конференций'],
        ],
        'premier-league-gb-eng' => [
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
            'tier' => 1,
            'sort_order' => 60,
            'short_name' => 'EPL',
            'short_name_i18n' => ['ru' => 'АПЛ'],
            'name_i18n' => ['ru' => 'Премьер-лига'],
        ],
        'laliga' => [
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
            'tier' => 1,
            'sort_order' => 70,
            'short_name' => 'LaLiga',
            'short_name_i18n' => ['ru' => 'Ла Лига'],
            'name_i18n' => ['ru' => 'Ла Лига'],
        ],
        'bundesliga' => [
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
            'tier' => 1,
            'sort_order' => 80,
            'short_name' => 'BL',
            'short_name_i18n' => ['ru' => 'Бундеслига'],
            'name_i18n' => ['ru' => 'Бундеслига'],
        ],
        'serie-a' => [
            'type' => CompetitionType::League,
            'status' => CompetitionStatus::Live,
            'tier' => 1,
            'sort_order' => 90,
            'short_name' => 'SA',
            'short_name_i18n' => ['ru' => 'Серия A'],
            'name_i18n' => ['ru' => 'Серия A'],
        ],
    ];

    /** Русские названия стран и регионов, которые приходят из SStats только на английском. */
    private const COUNTRIES_RU = [
        'GB-ENG' => 'Англия',
        'ES' => 'Испания',
        'DE' => 'Германия',
        'IT' => 'Италия',
        'EUR' => 'Европа',
    ];

    public function run(): void
    {
        Competition::whereIn('slug', self::ENABLED_SLUGS)->update(['ingest_enabled' => true]);

        foreach (self::SHOWCASE as $slug => $attributes) {
            Competition::where('slug', $slug)->first()?->forceFill($attributes)->save();
        }

        foreach (self::COUNTRIES_RU as $code => $name) {
            $country = Country::where('code', $code)->first();
            if ($country !== null && ($country->name_i18n['ru'] ?? null) !== $name) {
                $country->forceFill(['name_i18n' => [...($country->name_i18n ?? []), 'ru' => $name]])->save();
            }
        }

        $this->disableQualificationOnlyEurocupTeams();
    }

    /**
     * Команда остаётся в плановой синхронизации составов, если играет основной этап еврокубка текущего сезона
     * либо чемпионат/кубок из `ENABLED_SLUGS`; клубы, дошедшие максимум до квалификации (Qualification),
     * выключаются, чтобы суточный `squads` не тратил на них лимит SStats.
     */
    private function disableQualificationOnlyEurocupTeams(): void
    {
        $eurocupIds = Competition::whereIn('slug', self::EUROCUP_SLUGS)->pluck('id');
        if ($eurocupIds->isEmpty()) {
            return;
        }

        $currentStageTeams = fn () => DB::table('stage_teams')
            ->join('stages', 'stages.id', '=', 'stage_teams.stage_id')
            ->join('seasons', 'seasons.id', '=', 'stages.season_id')
            ->where('seasons.is_current', true);

        $mainStage = $currentStageTeams()
            ->whereIn('seasons.competition_id', $eurocupIds)
            ->where('stages.type', '!=', StageType::Knockout->value)
            ->pluck('stage_teams.team_id');

        $domestic = $currentStageTeams()
            ->whereNotIn('seasons.competition_id', $eurocupIds)
            ->whereIn('seasons.competition_id', Competition::where('ingest_enabled', true)->select('id'))
            ->pluck('stage_teams.team_id');

        $qualificationOnly = $currentStageTeams()
            ->whereIn('seasons.competition_id', $eurocupIds)
            ->pluck('stage_teams.team_id')
            ->diff($mainStage)
            ->diff($domestic)
            ->unique();

        DB::table('teams')->whereIn('id', $qualificationOnly->all())->update(['ingest_enabled' => false]);
    }
}
