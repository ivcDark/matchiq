<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Сопоставление имён людей из разных источников: «A. Mostovoy», «Rodrigo Becão» и «Andrey Mostovoy» — один человек.
 * Однозначное совпадение или null: при двух и более кандидатах лучше не угадывать.
 */
final class PersonName
{
    /** @return list<string> нормализованные слова: латиница в нижнем регистре без диакритики и знаков препинания */
    public static function tokens(string $name): array
    {
        $ascii = Str::lower(Str::ascii($name));
        $ascii = str_replace(["'", '’', '`'], '', $ascii);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : array_values($words);
    }

    /**
     * @param  iterable<int, string>  $candidates  id → полное имя
     * @return int|null id единственного подходящего кандидата
     */
    public static function findUnique(string $query, iterable $candidates): ?int
    {
        $words = self::tokens($query);
        if ($words === []) {
            return null;
        }

        $pool = [];
        foreach ($candidates as $id => $name) {
            $pool[$id] = self::tokens((string) $name);
        }

        $initials = array_values(array_filter($words, fn (string $w) => strlen($w) === 1));
        $surname = array_values(array_filter($words, fn (string $w) => strlen($w) > 1));

        if ($initials === [] && count($words) > 1) {
            $sorted = $words;
            sort($sorted);
            $exact = array_keys(array_filter($pool, function (array $tokens) use ($sorted): bool {
                sort($tokens);

                return $tokens === $sorted;
            }));
            if (count($exact) === 1) {
                return $exact[0];
            }
            if (count($exact) > 1) {
                return null;
            }

            $initials = [$words[0][0]];
            $surname = array_slice($words, 1);
        }

        if ($surname === []) {
            return null;
        }

        $matched = [];
        foreach ($pool as $id => $tokens) {
            if (array_diff($surname, $tokens) !== []) {
                continue;
            }
            $rest = array_values(array_diff($tokens, $surname));
            foreach ($initials as $letter) {
                $found = false;
                foreach ($rest as $token) {
                    if (str_starts_with($token, $letter)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    continue 2;
                }
            }
            $matched[] = $id;
        }

        return count($matched) === 1 ? $matched[0] : null;
    }
}
