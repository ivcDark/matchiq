<?php

namespace App\Models;

use App\Enums\PickStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pick extends Model
{
    protected $fillable = [
        'user_id',
        'match_id',
        'prediction_outcome_id',
        'market_type_id',
        'outcome_key',
        'line',
        'probability_at_pick',
        'status',
        'points',
        'picked_at',
        'superseded_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'probability_at_pick' => 'decimal:4',
            'status' => PickStatus::class,
            'points' => 'integer',
            'picked_at' => 'datetime',
            'superseded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function predictionOutcome(): BelongsTo
    {
        return $this->belongsTo(PredictionOutcome::class);
    }

    public function marketType(): BelongsTo
    {
        return $this->belongsTo(MarketType::class);
    }

    /** Действующий выбор (не заменённый новым). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PickStatus::Pending);
    }
}
