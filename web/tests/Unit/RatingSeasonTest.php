<?php

namespace Tests\Unit;

use App\Support\RatingSeason;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RatingSeasonTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function boundaries(): array
    {
        return [
            '31 июля — ещё прошлый сезон' => ['2026-07-31', '2025/26', '2025-08-01', '2026-07-31'],
            '1 августа — новый сезон' => ['2026-08-01', '2026/27', '2026-08-01', '2027-07-31'],
            'середина сезона (январь)' => ['2027-01-15', '2026/27', '2026-08-01', '2027-07-31'],
            'последний день сезона' => ['2027-07-31', '2026/27', '2026-08-01', '2027-07-31'],
            'день после сезона' => ['2027-08-01', '2027/28', '2027-08-01', '2028-07-31'],
            'смена века в подписи' => ['2099-09-10', '2099/00', '2099-08-01', '2100-07-31'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_season_boundaries(string $date, string $label, string $start, string $end): void
    {
        $season = RatingSeason::forDate(CarbonImmutable::parse($date.' 12:00:00'));

        $this->assertSame($label, $season->label());
        $this->assertSame($start, $season->start->toDateString());
        $this->assertSame($end, $season->end->toDateString());
    }

    public function test_labels(): void
    {
        $season = RatingSeason::forDate(CarbonImmutable::parse('2026-11-24'));

        $this->assertSame('Сезон 2026/27', $season->title());
        $this->assertSame('1 авг 2026 — 31 июл 2027', $season->range());
        $this->assertSame(
            'Сезон рейтинга действует с 1 августа по 31 июля и не зависит от сезонов чемпионатов',
            RatingSeason::HINT,
        );
    }

    public function test_contains_covers_whole_boundary_days(): void
    {
        $season = RatingSeason::forDate(CarbonImmutable::parse('2026-11-24'));

        $this->assertFalse($season->contains(CarbonImmutable::parse('2026-07-31 23:59:59')));
        $this->assertTrue($season->contains(CarbonImmutable::parse('2026-08-01 00:00:00')));
        $this->assertTrue($season->contains(CarbonImmutable::parse('2027-07-31 23:59:59')));
        $this->assertFalse($season->contains(CarbonImmutable::parse('2027-08-01 00:00:00')));
    }

    public function test_to_array_shape(): void
    {
        $data = RatingSeason::forDate(CarbonImmutable::parse('2026-08-01'))->toArray();

        $this->assertSame('2026/27', $data['label']);
        $this->assertSame('Сезон 2026/27', $data['title']);
        $this->assertSame('2026-08-01', $data['start']);
        $this->assertSame('2027-07-31', $data['end']);
        $this->assertSame(RatingSeason::HINT, $data['hint']);
    }
}
