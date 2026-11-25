<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'country_id',
        'slug',
        'name',
        'name_i18n',
        'first_name',
        'last_name',
        'birth_date',
        'position',
        'height_cm',
        'weight_kg',
        'preferred_foot',
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'name_i18n' => 'array',
            'birth_date' => 'date',
            'position' => PlayerPosition::class,
            'height_cm' => 'integer',
            'weight_kg' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function squadMemberships(): HasMany
    {
        return $this->hasMany(SquadMembership::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'squad_memberships')
            ->withPivot(['shirt_number', 'position', 'joined_on', 'left_on', 'kind'])
            ->withTimestamps();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(PlayerAbsence::class);
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(MatchPlayerStat::class);
    }

    public function seasonStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }
}
