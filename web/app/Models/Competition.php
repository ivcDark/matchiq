<?php

namespace App\Models;

use App\Enums\CompetitionScope;
use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\ParticipantType;
use App\Models\Concerns\HasExternalIds;
use App\Models\Concerns\HasI18nNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Competition extends Model
{
    use HasExternalIds, HasI18nNames;

    protected $fillable = [
        'country_id',
        'slug',
        'name',
        'name_i18n',
        'short_name',
        'short_name_i18n',
        'type',
        'scope',
        'participant_type',
        'tier',
        'status',
        'sort_order',
        'logo_url',
    ];

    protected function casts(): array
    {
        return [
            'name_i18n' => 'array',
            'short_name_i18n' => 'array',
            'type' => CompetitionType::class,
            'scope' => CompetitionScope::class,
            'participant_type' => ParticipantType::class,
            'status' => CompetitionStatus::class,
            'tier' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function currentSeason(): HasOne
    {
        return $this->hasOne(Season::class)->where('is_current', true);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(CompetitionSource::class);
    }

    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }

    public function leaderboardEntries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class);
    }
}
