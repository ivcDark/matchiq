<?php

namespace App\Models;

use App\Enums\PredictionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchPrediction extends Model
{
    protected $fillable = [
        'match_id',
        'model_version_id',
        'kind',
        'is_current',
        'expected_home_goals',
        'expected_away_goals',
        'p_home',
        'p_draw',
        'p_away',
        'generated_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PredictionKind::class,
            'is_current' => 'boolean',
            'expected_home_goals' => 'decimal:3',
            'expected_away_goals' => 'decimal:3',
            'p_home' => 'decimal:4',
            'p_draw' => 'decimal:4',
            'p_away' => 'decimal:4',
            'generated_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(PredictionOutcome::class);
    }

    public function scorelines(): HasMany
    {
        return $this->hasMany(PredictionScoreline::class);
    }

    public function previews(): HasMany
    {
        return $this->hasMany(MatchPreview::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
