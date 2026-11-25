<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSeasonStat extends Model
{
    protected $fillable = [
        'season_id',
        'competition_id',
        'player_id',
        'team_id',
        'appearances',
        'starts',
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
        'rating_sum',
        'rated_matches',
        'motm_count',
        'clean_sheets',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'xg' => 'decimal:2',
            'xa' => 'decimal:2',
            'rating_sum' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    /** Средний рейтинг = rating_sum / rated_matches (NULL, если оценок нет). */
    public function averageRating(): ?float
    {
        return $this->rated_matches > 0 ? round((float) $this->rating_sum / $this->rated_matches, 2) : null;
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
