<?php

namespace App\Repositories;

use App\Enums\UpsertOutcome;
use App\Models\FootballMatch;
use App\Models\Stage;
use App\Models\StageTeam;
use App\Models\Team;
use App\Repositories\Concerns\GeneratesUniqueSlugs;
use App\Repositories\Concerns\UpsertsModels;

class TeamRepository
{
    use GeneratesUniqueSlugs, UpsertsModels;

    public function find(int $id): ?Team
    {
        return Team::find($id);
    }

    public function findBySlug(string $slug): ?Team
    {
        return Team::where('slug', $slug)->first();
    }

    /**
     * Команды сезона: участники стадий и клубы матчей сезона.
     *
     * @return list<int>
     */
    public function idsInSeason(int $seasonId): array
    {
        $stageTeams = StageTeam::query()
            ->whereIn('stage_id', Stage::where('season_id', $seasonId)->select('id'))
            ->pluck('team_id');
        $home = FootballMatch::where('season_id', $seasonId)->pluck('home_team_id');
        $away = FootballMatch::where('season_id', $seasonId)->pluck('away_team_id');

        return $stageTeams->merge($home)->merge($away)->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return array{0: Team, 1: UpsertOutcome} */
    public function upsert(
        ?Team $existing,
        int $countryId,
        string $name,
        ?string $shortName,
        ?string $logoUrl,
        string $countryCode = '',
    ): array {
        $onCreate = [];
        if ($existing === null) {
            $onCreate = ['slug' => $this->uniqueSlug(Team::query(), $name, [$countryCode])];
        }

        return $this->persist(new Team, $existing, [
            'country_id' => $countryId,
            'name' => $name,
            'short_name' => $shortName,
            'logo_url' => $logoUrl,
        ], $onCreate);
    }
}
