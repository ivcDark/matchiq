<?php

namespace App\Models;

use App\Enums\MarketCategory;
use App\Enums\MarketSubject;
use App\Enums\StatsPeriod;
use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketType extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'code',
        'category',
        'name',
        'name_i18n',
        'period',
        'subject',
        'has_line',
        'sort_order',
        'is_popular',
    ];

    protected function casts(): array
    {
        return [
            'category' => MarketCategory::class,
            'name_i18n' => 'array',
            'period' => StatsPeriod::class,
            'subject' => MarketSubject::class,
            'has_line' => 'boolean',
            'sort_order' => 'integer',
            'is_popular' => 'boolean',
        ];
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(MarketTypeOutcome::class)->orderBy('sort_order');
    }

    public function predictionOutcomes(): HasMany
    {
        return $this->hasMany(PredictionOutcome::class);
    }

    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class);
    }
}
