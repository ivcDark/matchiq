<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionScoreline extends Model
{
    protected $fillable = [
        'match_prediction_id',
        'home_goals',
        'away_goals',
        'probability',
    ];

    protected function casts(): array
    {
        return [
            'home_goals' => 'integer',
            'away_goals' => 'integer',
            'probability' => 'decimal:4',
        ];
    }

    public function matchPrediction(): BelongsTo
    {
        return $this->belongsTo(MatchPrediction::class);
    }
}
