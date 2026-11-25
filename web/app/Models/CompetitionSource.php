<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionSource extends Model
{
    protected $fillable = [
        'competition_id',
        'source_provider_id',
        'priority',
        'is_enabled',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class);
    }
}
