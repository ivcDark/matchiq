<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPreview extends Model
{
    protected $fillable = [
        'match_id',
        'match_prediction_id',
        'locale',
        'preview_text',
        'facts',
        'generator',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function matchPrediction(): BelongsTo
    {
        return $this->belongsTo(MatchPrediction::class);
    }
}
