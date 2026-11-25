<?php

namespace App\Models;

use App\Enums\IngestionKind;
use App\Enums\IngestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionRun extends Model
{
    protected $fillable = [
        'source_provider_id',
        'competition_id',
        'kind',
        'status',
        'started_at',
        'finished_at',
        'stats',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'kind' => IngestionKind::class,
            'status' => IngestionStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
        ];
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(IngestionRunError::class);
    }
}
