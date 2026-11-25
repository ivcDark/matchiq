<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Enums\SquadKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SquadMembership extends Model
{
    protected $fillable = [
        'team_id',
        'player_id',
        'shirt_number',
        'position',
        'joined_on',
        'left_on',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'shirt_number' => 'integer',
            'position' => PlayerPosition::class,
            'joined_on' => 'date',
            'left_on' => 'date',
            'kind' => SquadKind::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Заявка, действующая на дату: joined_on <= date и (left_on IS NULL или left_on >= date). */
    public function scopeActiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('joined_on')->orWhere('joined_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date));
    }
}
