<?php

namespace App\Models;

use App\Enums\SourceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceProvider extends Model
{
    protected $fillable = [
        'code',
        'name',
        'kind',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function competitionSources(): HasMany
    {
        return $this->hasMany(CompetitionSource::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(ExternalId::class);
    }

    public function teamAliases(): HasMany
    {
        return $this->hasMany(TeamAlias::class);
    }

    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }
}
