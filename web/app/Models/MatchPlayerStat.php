<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPlayerStat extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'player_id',
        'minutes',
        'goals',
        'assists',
        'penalties_scored',
        'shots',
        'shots_on_target',
        'xg',
        'xa',
        'yellow_cards',
        'red_cards',
        'rating',
        'is_motm',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'xg' => 'decimal:2',
            'xa' => 'decimal:2',
            'rating' => 'decimal:2',
            'is_motm' => 'boolean',
            'extra' => 'array',
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

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
