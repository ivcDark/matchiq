<?php

namespace App\Domains\Matches;

/**
 * Мультивыбор лиг из query: `all` | `rpl,ucl` | `league[]=rpl&league[]=ucl`.
 * «all» эксклюзивно; неизвестные и «скоро»-лиги отбрасываются; пустой набор → all.
 */
final readonly class LeagueSelection
{
    public const ALL = 'all';

    /** @param  list<string>  $slugs */
    private function __construct(public array $slugs) {}

    /**
     * @param  list<string>  $activeSlugs  slug'и активных (live) лиг
     */
    public static function resolve(mixed $raw, array $activeSlugs): self
    {
        $tokens = is_array($raw) ? $raw : preg_split('/\s*,\s*/', (string) $raw);

        $tokens = array_values(array_filter(array_map(
            static fn ($token) => is_string($token) ? trim($token) : '',
            $tokens ?: [],
        ), static fn (string $token) => $token !== '' && $token !== self::ALL));

        $selected = array_values(array_unique(array_filter(
            $tokens,
            static fn (string $slug) => in_array($slug, $activeSlugs, true),
        )));

        return new self($selected === [] ? [self::ALL] : $selected);
    }

    public function isAll(): bool
    {
        return $this->slugs === [self::ALL];
    }

    /**
     * Slug'и, по которым читать матчи: при «all» — все активные.
     *
     * @param  list<string>  $activeSlugs
     * @return list<string>
     */
    public function effective(array $activeSlugs): array
    {
        return $this->isAll() ? $activeSlugs : $this->slugs;
    }

    /** Лига для виджетов: первая выбранная, для «all» — первая активная. */
    public function widgetSlug(array $activeSlugs): ?string
    {
        return $this->effective($activeSlugs)[0] ?? null;
    }
}
