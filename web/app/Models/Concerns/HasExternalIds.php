<?php

namespace App\Models\Concerns;

use App\Models\ExternalId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Связь с маппингом внешних идентификаторов (`external_ids`, полиморфно без FK).
 * Алиасы типов — в morph map (AppServiceProvider).
 *
 * @mixin Model
 */
trait HasExternalIds
{
    public function externalIds(): MorphMany
    {
        return $this->morphMany(ExternalId::class, 'entity', 'entity_type', 'entity_id');
    }
}
