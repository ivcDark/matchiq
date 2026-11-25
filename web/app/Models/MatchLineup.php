<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchLineup extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'player_id',
        'is_starter',
        'is_captain',
        'shirt_number',
        'position',
        'line',
        'sort_order',
        'minute_in',
        'minute_out',
    ];

    protected function casts(): array
    {
        return [
            'is_starter' => 'boolean',
            'is_captain' => 'boolean',
            'shirt_number' => 'integer',
            'position' => PlayerPosition::class,
            'sort_order' => 'integer',
            'minute_in' => 'integer',
            'minute_out' => 'integer',
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
