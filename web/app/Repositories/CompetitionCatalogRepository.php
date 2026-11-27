<?php

namespace App\Repositories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Support\Collection;

/** Лиги для витрины: только live и «скоро», скрытые (hidden) не отдаются. */
class CompetitionCatalogRepository
{
    /**
     * @return Collection<int, Competition>
     */
    public function listVisible(): Collection
    {
        return Competition::query()
            ->with('country')
            ->whereIn('status', [CompetitionStatus::Live, CompetitionStatus::Soon])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
