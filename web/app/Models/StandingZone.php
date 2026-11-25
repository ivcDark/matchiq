<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandingZone extends Model
{
    protected $fillable = [
        'stage_id',
        'from_position',
        'to_position',
        'code',
        'label',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'from_position' => 'integer',
            'to_position' => 'integer',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }
}
