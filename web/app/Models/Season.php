<?php

namespace App\Models;

use App\Models\Concerns\HasExternalIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasExternalIds;

    protected $fillable = [
        'competition_id',
        'label',
        'slug',
        'starts_on',
        'ends_on',
        'is_current',
        'rounds_total',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'rounds_total' => 'integer',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('stage_order');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }
}
