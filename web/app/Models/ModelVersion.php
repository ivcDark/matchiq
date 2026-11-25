<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    protected $fillable = [
        'code',
        'name',
        'version',
        'description',
        'is_active',
        'trained_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trained_at' => 'datetime',
        ];
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(MatchPrediction::class);
    }
}
