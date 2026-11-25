<?php

namespace App\Models;

use App\Enums\CountryKind;
use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'code',
        'kind',
        'name',
        'name_i18n',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CountryKind::class,
            'name_i18n' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(Coach::class);
    }

    public function referees(): HasMany
    {
        return $this->hasMany(Referee::class);
    }
}
