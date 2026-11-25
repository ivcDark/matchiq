<?php

namespace App\Models;

use App\Enums\IngestionPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ошибка по одной сущности в рамках прогона. `entity_type` — значение ExternalEntityType
 * (строка без morph: `entity_id` может быть ещё неизвестен, если сущность не создана).
 */
class IngestionRunError extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ingestion_run_id',
        'entity_type',
        'external_id',
        'entity_id',
        'phase',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'phase' => IngestionPhase::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ingestionRun(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class);
    }
}
