<?php

namespace App\Models;

use App\Enums\TieDecidedBy;
use App\Enums\TieSlot;
use App\Enums\TieStatus;
use App\Models\Concerns\HasExternalIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tie extends Model
{
    use HasExternalIds;

    protected $fillable = [
        'stage_id',
        'round_id',
        'tie_number',
        'team_a_id',
        'team_b_id',
        'source_a',
        'source_b',
        'legs',
        'status',
        'winner_team_id',
        'decided_by',
        'aggregate_a',
        'aggregate_b',
        'penalties_a',
        'penalties_b',
        'next_tie_id',
        'next_tie_slot',
    ];

    protected function casts(): array
    {
        return [
            'tie_number' => 'integer',
            'source_a' => 'array',
            'source_b' => 'array',
            'legs' => 'integer',
            'status' => TieStatus::class,
            'decided_by' => TieDecidedBy::class,
            'aggregate_a' => 'integer',
            'aggregate_b' => 'integer',
            'penalties_a' => 'integer',
            'penalties_b' => 'integer',
            'next_tie_slot' => TieSlot::class,
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function teamA(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_a_id');
    }

    public function teamB(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_b_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function nextTie(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_tie_id');
    }

    public function previousTies(): HasMany
    {
        return $this->hasMany(self::class, 'next_tie_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class)->orderBy('leg');
    }
}
