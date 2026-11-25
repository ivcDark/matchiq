<?php

namespace App\Models;

use App\Enums\StageType;
use App\Models\Concerns\HasExternalIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    use HasExternalIds;

    protected $fillable = [
        'season_id',
        'name',
        'type',
        'stage_order',
        'cycles',
        'matches_per_team',
        'has_standings',
        'default_legs',
        'has_third_place_match',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'type' => StageType::class,
            'stage_order' => 'integer',
            'cycles' => 'integer',
            'matches_per_team' => 'integer',
            'has_standings' => 'boolean',
            'default_legs' => 'integer',
            'has_third_place_match' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(StageGroup::class)->orderBy('group_order');
    }

    public function stageTeams(): HasMany
    {
        return $this->hasMany(StageTeam::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'stage_teams')
            ->withPivot(['stage_group_id', 'seed', 'initial_points'])
            ->withTimestamps();
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class)->orderBy('number');
    }

    public function ties(): HasMany
    {
        return $this->hasMany(Tie::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function standingZones(): HasMany
    {
        return $this->hasMany(StandingZone::class)->orderBy('from_position');
    }
}
