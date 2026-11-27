<?php

namespace App\Models;

use App\Enums\TeamType;
use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'country_id',
        'home_venue_id',
        'parent_team_id',
        'slug',
        'type',
        'name',
        'name_i18n',
        'short_name',
        'short_name_i18n',
        'city',
        'city_i18n',
        'founded_year',
        'logo_url',
        'primary_color',
        'secondary_color',
        'is_active',
        'ingest_enabled',
    ];

    protected function casts(): array
    {
        return [
            'type' => TeamType::class,
            'name_i18n' => 'array',
            'short_name_i18n' => 'array',
            'city_i18n' => 'array',
            'founded_year' => 'integer',
            'is_active' => 'boolean',
            'ingest_enabled' => 'boolean',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function homeVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'home_venue_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_team_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_team_id');
    }

    public function squadMemberships(): HasMany
    {
        return $this->hasMany(SquadMembership::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'squad_memberships')
            ->withPivot(['shirt_number', 'position', 'joined_on', 'left_on', 'kind'])
            ->withTimestamps();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(PlayerAbsence::class);
    }

    public function stageTeams(): HasMany
    {
        return $this->hasMany(StageTeam::class);
    }

    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(Stage::class, 'stage_teams')
            ->withPivot(['stage_group_id', 'seed', 'initial_points'])
            ->withTimestamps();
    }

    public function homeMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'home_team_id');
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'away_team_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function playerSeasonStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TeamAlias::class);
    }
}
