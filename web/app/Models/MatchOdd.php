<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchOdd extends Model
{
    protected $fillable = [
        'match_id',
        'source_provider_id',
        'source_market_id',
        'market_name',
        'outcome',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'source_market_id' => 'integer',
            'value' => 'decimal:3',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
