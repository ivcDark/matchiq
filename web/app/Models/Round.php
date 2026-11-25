<?php

namespace App\Models;

use App\Enums\RoundCode;
use App\Enums\RoundType;
use App\Models\Concerns\HasExternalIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    use HasExternalIds;

    protected $fillable = [
        'stage_id',
        'number',
        'type',
        'code',
        'name',
        'legs',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'type' => RoundType::class,
            'code' => RoundCode::class,
            'legs' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function ties(): HasMany
    {
        return $this->hasMany(Tie::class)->orderBy('tie_number');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }
}
