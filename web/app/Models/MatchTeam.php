<?php

namespace App\Models;

use App\Enums\MatchSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchTeam extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'coach_id',
        'side',
        'formation',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'side' => MatchSide::class,
            'rating' => 'decimal:2',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }
}
