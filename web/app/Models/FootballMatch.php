<?php

namespace App\Models;

use App\Enums\MatchPeriod;
use App\Enums\MatchStatus;
use App\Models\Concerns\HasExternalIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Матч (таблица `matches`). Класс не может называться `Match` — зарезервированное слово PHP.
 */
class FootballMatch extends Model
{
    use HasExternalIds;

    protected $table = 'matches';

    protected $fillable = [
        'competition_id',
        'season_id',
        'stage_id',
        'round_id',
        'stage_group_id',
        'tie_id',
        'home_team_id',
        'away_team_id',
        'venue_id',
        'referee_id',
        'leg',
        'kickoff_at',
        'status',
        'period',
        'minute',
        'home_goals',
        'away_goals',
        'home_goals_ht',
        'away_goals_ht',
        'home_goals_et',
        'away_goals_et',
        'home_pens',
        'away_pens',
        'is_neutral_venue',
        'attendance',
        'weather_condition',
        'temperature_c',
        'wind_speed_ms',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'leg' => 'integer',
            'kickoff_at' => 'datetime',
            'status' => MatchStatus::class,
            'period' => MatchPeriod::class,
            'minute' => 'integer',
            'home_goals' => 'integer',
            'away_goals' => 'integer',
            'home_goals_ht' => 'integer',
            'away_goals_ht' => 'integer',
            'home_goals_et' => 'integer',
            'away_goals_et' => 'integer',
            'home_pens' => 'integer',
            'away_pens' => 'integer',
            'is_neutral_venue' => 'boolean',
            'attendance' => 'integer',
            'temperature_c' => 'decimal:1',
            'wind_speed_ms' => 'decimal:1',
            'finished_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function stageGroup(): BelongsTo
    {
        return $this->belongsTo(StageGroup::class);
    }

    public function tie(): BelongsTo
    {
        return $this->belongsTo(Tie::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }

    public function matchTeams(): HasMany
    {
        return $this->hasMany(MatchTeam::class, 'match_id');
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class, 'match_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id')->orderBy('sort_order');
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(MatchTeamStat::class, 'match_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(MatchPlayerStat::class, 'match_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(MatchPrediction::class, 'match_id');
    }

    public function currentPrediction(): HasOne
    {
        return $this->hasOne(MatchPrediction::class, 'match_id')->where('is_current', true);
    }

    public function previews(): HasMany
    {
        return $this->hasMany(MatchPreview::class, 'match_id');
    }

    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class, 'match_id');
    }
}
