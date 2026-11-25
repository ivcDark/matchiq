<?php

namespace App\Models;

use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTypeOutcome extends Model
{
    use HasI18nNames;

    protected $fillable = [
        'market_type_id',
        'outcome_key',
        'label',
        'label_i18n',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'label_i18n' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function marketType(): BelongsTo
    {
        return $this->belongsTo(MarketType::class);
    }
}
