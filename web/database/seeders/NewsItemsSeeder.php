<?php

namespace Database\Seeders;

use App\Models\NewsItem;
use Illuminate\Database\Seeder;

/**
 * Четыре русские заглушки для блока «Новости» на главной.
 * Повторный запуск обновляет те же slug и сдвигает published_at от текущего момента.
 *
 * php artisan db:seed --class=NewsItemsSeeder
 */
class NewsItemsSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'slug' => 'rpl-zenit-spartak-preview',
                'tag' => 'РПЛ',
                'title' => '«Зенит» принимает «Спартак»: MatchIQ отдаёт преимущество хозяевам',
                'published_at' => now()->subHours(3),
            ],
            [
                'slug' => 'rpl-krasnodar-lead',
                'tag' => 'РПЛ',
                'title' => '«Краснодар» удерживает первое место после победы над «Ростовом»',
                'published_at' => now()->subDay(),
            ],
            [
                'slug' => 'matchiq-how-to-read-probabilities',
                'tag' => 'MatchIQ',
                'title' => 'Как читать вероятности П1, ничьей и П2 перед туром РПЛ',
                'published_at' => now()->subDays(2),
            ],
            [
                'slug' => 'russian-cup-quarterfinals',
                'tag' => 'Кубок',
                'title' => 'Кубок России: четвертьфинал начнётся после стыковых матчей',
                'published_at' => now()->subDays(4),
            ],
        ];

        foreach ($items as $item) {
            NewsItem::query()->updateOrCreate(
                ['slug' => $item['slug']],
                $item,
            );
        }
    }
}
