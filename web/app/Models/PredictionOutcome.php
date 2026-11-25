<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Строки неизменяемы после записи: новый прогон = новый MatchPrediction.
 */
class PredictionOutcome extends Model
{
    protected $fillable = [
        'match_prediction_id',
        'market_type_id',
        'outcome_key',
        'line',
        'probability',
        'is_hit',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'probability' => 'decimal:4',
            'is_hit' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function matchPrediction(): BelongsTo
    {
        return $this->belongsTo(MatchPrediction::class);
    }

    public function marketType(): BelongsTo
    {
        return $this->belongsTo(MarketType::class);
    }

    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class);
    }
}
