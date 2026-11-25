<?php

namespace App\Models;

use App\Enums\StandingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Standing extends Model
{
    protected $fillable = [
        'stage_id',
        'stage_group_id',
        'round_id',
        'team_id',
        'context',
        'position',
        'played',
        'wins',
        'draws',
        'losses',
        'goals_for',
        'goals_against',
        'goal_diff',
        'points',
        'form',
        'zone_code',
        'is_current',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => StandingContext::class,
            'is_current' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function stageGroup(): BelongsTo
    {
        return $this->belongsTo(StageGroup::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
