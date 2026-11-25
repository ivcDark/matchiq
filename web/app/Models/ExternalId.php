<?php

namespace App\Models;

use App\Enums\MatchedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Полиморфная связь без FK: `entity_type` — алиас из morph map (AppServiceProvider),
 * `entity_type` намеренно не кастуется в enum, чтобы morphTo() читал строковый алиас.
 */
class ExternalId extends Model
{
    protected $fillable = [
        'source_provider_id',
        'entity_type',
        'entity_id',
        'external_id',
        'external_url',
        'matched_by',
        'last_seen_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'matched_by' => MatchedBy::class,
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }
}
