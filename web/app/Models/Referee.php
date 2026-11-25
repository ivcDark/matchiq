<?php

namespace App\Models;

use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referee extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'country_id',
        'name',
        'name_i18n',
        'city',
    ];

    protected function casts(): array
    {
        return [
            'name_i18n' => 'array',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }
}
