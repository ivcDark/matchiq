<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Новость раздела «Новости»: тег, заголовок, картинка, анонс, полный текст и время публикации. */
class NewsItem extends Model
{
    protected $fillable = [
        'slug',
        'tag',
        'title',
        'image_url',
        'excerpt',
        'body',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
