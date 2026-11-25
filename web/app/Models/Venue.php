<?php

namespace App\Models;

use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'country_id',
        'name',
        'name_i18n',
        'city',
        'city_i18n',
        'capacity',
        'surface',
        'lat',
        'lng',
    ];

    protected function casts(): array
    {
        return [
            'name_i18n' => 'array',
            'city_i18n' => 'array',
            'capacity' => 'integer',
            'lat' => 'decimal:6',
            'lng' => 'decimal:6',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'home_venue_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }
}
