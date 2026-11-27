<?php

namespace Database\Seeders;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Country;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Публикует РПЛ на витрине (status=live, порядок групп) и добавляет русские названия.
 * Ingest создаёт лигу скрытой и пишет только EN-значения; этот сидер правит только отображение.
 */
class RussianPremierLeagueSeeder extends Seeder
{
    private const COMPETITION_SLUG = 'premier-league';

    private const COUNTRY_CODE = 'RU';

    /** @var array<string, string> */
    private const TEAMS_RU = [
        'Baltika' => 'Балтика',
        'Akron Togliatti' => 'Акрон',
        'Dynamo Makhachkala' => 'Динамо Мх',
        'Dynamo Moscow' => 'Динамо',
        'Akhmat Grozny' => 'Ахмат',
        'Krasnodar' => 'Краснодар',
        'FK Rostov' => 'Ростов',
        'Spartak Moscow' => 'Спартак',
        'Fakel Voronezh' => 'Факел',
        'CSKA Moscow' => 'ЦСКА',
        'Rodina Moscow' => 'Родина',
        'Orenburg' => 'Оренбург',
        'Lokomotiv Moscow' => 'Локомотив',
        'Krylya Sovetov' => 'Крылья Советов',
        'Rubin Kazan' => 'Рубин',
        'Zenit' => 'Зенит',
    ];

    public function run(): void
    {
        $country = Country::where('code', self::COUNTRY_CODE)->first();
        if ($country === null) {
            return;
        }

        $country->setTranslation('name', 'ru', 'Россия')->save();

        $competition = Competition::where('slug', self::COMPETITION_SLUG)
            ->where('country_id', $country->id)
            ->first();

        if ($competition !== null) {
            $competition->fill(['status' => CompetitionStatus::Live, 'sort_order' => 10, 'short_name' => 'RPL']);
            $competition->setTranslation('name', 'ru', 'Премьер-лига')
                ->setTranslation('short_name', 'ru', 'РПЛ')
                ->save();
        }

        foreach (Team::where('country_id', $country->id)->whereIn('name', array_keys(self::TEAMS_RU))->get() as $team) {
            $team->setTranslation('name', 'ru', self::TEAMS_RU[$team->name])->save();
        }
    }
}
