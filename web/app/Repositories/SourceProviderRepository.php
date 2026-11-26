<?php

namespace App\Repositories;

use App\Enums\SourceKind;
use App\Enums\SourceProviderCode;
use App\Models\SourceProvider;

class SourceProviderRepository
{
    public function ensure(SourceProviderCode $code): SourceProvider
    {
        return SourceProvider::firstOrCreate(
            ['code' => $code->value],
            ['name' => $code->label(), 'kind' => SourceKind::Api, 'is_active' => true],
        );
    }

    public function findByCode(string $code): ?SourceProvider
    {
        return SourceProvider::where('code', $code)->first();
    }
}
