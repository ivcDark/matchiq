<?php

namespace Database\Seeders;

use App\Repositories\MarketTypeRepository;
use Illuminate\Database\Seeder;

/** Каталог рынков MVP (исход, двойной шанс, тоталы, обе забьют, угловые, карточки). Идемпотентен. */
class MarketTypesSeeder extends Seeder
{
    public function run(MarketTypeRepository $markets): void
    {
        $markets->syncCatalog();
    }
}
