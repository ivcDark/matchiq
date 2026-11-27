<?php

namespace Tests\Feature;

use App\Models\NewsItem;
use Database\Seeders\NewsItemsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(NewsItemsSeeder::class);
        $this->seed(NewsItemsSeeder::class);

        $this->assertSame(15, NewsItem::count());
        $this->assertSame(0, NewsItem::whereNull('image_url')->orWhereNull('body')->orWhereNull('excerpt')->count());
    }

    public function test_index_lists_news_with_pagination(): void
    {
        $this->seed(NewsItemsSeeder::class);

        $this->get('/news')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('News/Index')
                ->has('items', 12)
                ->has('items.0.slug')
                ->has('items.0.image_url')
                ->has('items.0.excerpt')
                ->where('pagination.total', 15)
                ->where('pagination.last_page', 2));

        $this->get('/news?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items', 3));
    }

    public function test_show_renders_full_item(): void
    {
        $this->seed(NewsItemsSeeder::class);

        $this->get('/news/rpl-zenit-spartak-preview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('News/Show')
                ->where('item.slug', 'rpl-zenit-spartak-preview')
                ->where('item.tag', 'РПЛ')
                ->has('item.body')
                ->has('item.published_at')
                ->has('more', 3));
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get('/news/no-such-news')->assertNotFound();
    }
}
