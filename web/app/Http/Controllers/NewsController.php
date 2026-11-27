<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    private const PER_PAGE = 12;

    public function index(): Response
    {
        $paginator = NewsItem::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (NewsItem $item) => $this->card($item));

        return Inertia::render('News/Index', [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function show(NewsItem $news): Response
    {
        $more = NewsItem::query()
            ->whereKeyNot($news->getKey())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (NewsItem $item) => $this->card($item))
            ->all();

        return Inertia::render('News/Show', [
            'item' => [
                ...$this->card($news),
                'body' => $news->body ?? $news->excerpt ?? '',
            ],
            'more' => $more,
        ]);
    }

    /** @return array<string, mixed> */
    private function card(NewsItem $item): array
    {
        return [
            'slug' => $item->slug,
            'tag' => $item->tag,
            'title' => $item->title,
            'image_url' => $item->image_url,
            'excerpt' => $item->excerpt,
            'published_at' => $item->published_at?->toIso8601String(),
        ];
    }
}
