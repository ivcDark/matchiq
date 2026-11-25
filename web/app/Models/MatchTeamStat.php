<?php

namespace App\Models;

use App\Enums\StatsPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchTeamStat extends Model
{
    protected $fillable = [
        'match_id',
        'team_id',
        'period',
        'xg',
        'xga',
        'shots',
        'shots_on_target',
        'corners',
        'fouls',
        'offsides',
        'yellow_cards',
        'red_cards',
        'possession_pct',
        'passes',
        'pass_accuracy_pct',
        'aerials_won',
        'saves',
        'extra',
    ];

    protected function casts(): array
    {
        return [
            'period' => StatsPeriod::class,
            'xg' => 'decimal:2',
            'xga' => 'decimal:2',
            'possession_pct' => 'decimal:2',
            'pass_accuracy_pct' => 'decimal:2',
            'extra' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
