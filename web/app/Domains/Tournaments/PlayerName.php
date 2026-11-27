<?php

namespace App\Domains\Tournaments;

/**
 * Компактное имя игрока для таблиц: «Имя Фамилия» → «И. Фамилия».
 * Уже сокращённые имена («А. Соболев», «Глушенков М.») приводятся к той же форме; одно слово и пустая строка остаются как есть.
 */
final class PlayerName
{
    public static function short(?string $name): string
    {
        $tokens = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2) {
            return $tokens[0] ?? '';
        }

        if (self::isInitial($tokens[0])) {
            return implode(' ', $tokens);
        }

        $last = $tokens[count($tokens) - 1];
        if (self::isInitial($last)) {
            return $last.' '.implode(' ', array_slice($tokens, 0, -1));
        }

        $first = array_shift($tokens);
        $letter = mb_strtoupper(mb_substr($first, 0, 1));

        return $letter.'. '.implode(' ', $tokens);
    }

    private static function isInitial(string $token): bool
    {
        return preg_match('/^\p{L}\.$/u', $token) === 1;
    }
}
