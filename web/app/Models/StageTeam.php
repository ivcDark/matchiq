<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageTeam extends Model
{
    protected $fillable = [
        'stage_id',
        'team_id',
        'stage_group_id',
        'seed',
        'initial_points',
    ];

    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'initial_points' => 'integer',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StageGroup::class, 'stage_group_id');
    }
}
