<?php

namespace App\Models;

use App\Enums\LeaderboardPeriodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardEntry extends Model
{
    protected $fillable = [
        'user_id',
        'period_type',
        'period_key',
        'competition_id',
        'rank',
        'points',
        'picks_count',
        'correct_count',
        'streak',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => LeaderboardPeriodType::class,
            'rank' => 'integer',
            'points' => 'integer',
            'picks_count' => 'integer',
            'correct_count' => 'integer',
            'streak' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
