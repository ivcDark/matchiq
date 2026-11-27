<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Промо-новость на главной: тег, заголовок и время публикации. */
class NewsItem extends Model
{
    protected $fillable = [
        'slug',
        'tag',
        'title',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
