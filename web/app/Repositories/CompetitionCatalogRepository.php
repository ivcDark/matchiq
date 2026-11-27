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

    /** Страница турнира открывается для live и «скоро»; скрытые лиги недоступны. */
    public function findVisible(int $id): ?Competition
    {
        return Competition::query()
            ->with('country')
            ->whereIn('status', [CompetitionStatus::Live, CompetitionStatus::Soon])
            ->find($id);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Competition>
     */
    public function visibleByIds(array $ids): Collection
    {
        return Competition::query()
            ->with('country')
            ->whereIn('id', $ids)
            ->whereIn('status', [CompetitionStatus::Live, CompetitionStatus::Soon])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }
}
