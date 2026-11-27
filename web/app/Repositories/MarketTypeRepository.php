<?php

namespace App\Repositories;

use App\Domains\Predictions\MarketCatalog;
use App\Enums\MarketCategory;
use App\Enums\MarketSubject;
use App\Enums\StatsPeriod;
use App\Models\MarketType;

class MarketTypeRepository
{
    /**
     * Идемпотентно приводит `market_types` / `market_type_outcomes` к каталогу MVP.
     *
     * @return array<string, MarketType> по коду рынка
     */
    public function syncCatalog(): array
    {
        $types = [];

        foreach (MarketCatalog::definitions() as $definition) {
            $type = MarketType::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'category' => MarketCategory::from($definition['category']),
                    'name' => $definition['name'],
                    'name_i18n' => ['ru' => $definition['name_ru']],
                    'period' => StatsPeriod::FullTime,
                    'subject' => MarketSubject::Match,
                    'has_line' => $definition['has_line'],
                    'sort_order' => $definition['sort_order'],
                    'is_popular' => $definition['is_popular'],
                ],
            );

            foreach ($definition['outcomes'] as $index => [$key, $label, $labelRu]) {
                $type->outcomes()->updateOrCreate(
                    ['outcome_key' => $key],
                    ['label' => $label, 'label_i18n' => ['ru' => $labelRu], 'sort_order' => $index + 1],
                );
            }

            $types[$definition['code']] = $type;
        }

        return $types;
    }
}
