<?php

namespace App\Models;

use App\Enums\AbsenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAbsence extends Model
{
    protected $fillable = [
        'player_id',
        'team_id',
        'type',
        'reason',
        'since',
        'expected_return',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AbsenceType::class,
            'since' => 'date',
            'expected_return' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
