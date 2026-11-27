<?php

namespace App\Domains\Rating;

/** Ранжирование игроков: очки, затем число угаданных; равные по обоим — одно место. */
final class LeaderboardRanking
{
    /**
     * @param  list<array{points: int, correct: int}&array<string, mixed>>  $rows
     * @return list<array<string, mixed>> строки с ключом `rank`, отсортированные по месту
     */
    public static function rank(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b['points'], $b['correct'], $a['picks'] ?? 0, $a['id'] ?? 0]
            <=> [$a['points'], $a['correct'], $b['picks'] ?? 0, $b['id'] ?? 0]);

        $rank = 0;
        $prev = null;
        foreach ($rows as $index => &$row) {
            $key = $row['points'].'|'.$row['correct'];
            if ($key !== $prev) {
                $rank = $index + 1;
                $prev = $key;
            }
            $row['rank'] = $rank;
        }
        unset($row);

        return $rows;
    }

    /** Угаданных подряд с самого свежего закрытого pick. */
    public static function streak(array $resultsNewestFirst): int
    {
        $streak = 0;
        foreach ($resultsNewestFirst as $won) {
            if (! $won) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
