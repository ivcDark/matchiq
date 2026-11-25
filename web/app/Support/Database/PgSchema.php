<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Помощники для миграций: индексы и ограничения, которых нет в Blueprint.
 *
 * Продуктовая БД — PostgreSQL; тесты идут на SQLite in-memory, поэтому
 * PG-специфика (INCLUDE, NULLS NOT DISTINCT, CHECK, fillfactor) на SQLite пропускается.
 */
final class PgSchema
{
    public static function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @param  string  $columns  Список колонок/выражений как в CREATE INDEX (допускается DESC).
     * @param  string|null  $where  Условие частичного индекса.
     * @param  string|null  $include  Покрывающие колонки (только PostgreSQL).
     * @param  bool  $nullsNotDistinct  UNIQUE с NULL как равными значениями (PostgreSQL 15+).
     * @param  bool  $pgOnly  Создавать индекс только на PostgreSQL (выражения LEAST/GREATEST и т.п.).
     */
    public static function index(
        string $table,
        string $name,
        string $columns,
        bool $unique = false,
        ?string $where = null,
        ?string $include = null,
        bool $nullsNotDistinct = false,
        bool $pgOnly = false,
    ): void {
        $pg = self::isPgsql();

        if ($pgOnly && ! $pg) {
            return;
        }

        $sql = 'CREATE '.($unique ? 'UNIQUE ' : '')."INDEX {$name} ON {$table} ({$columns})";

        if ($pg && $include !== null) {
            $sql .= " INCLUDE ({$include})";
        }

        if ($pg && $unique && $nullsNotDistinct) {
            $sql .= ' NULLS NOT DISTINCT';
        }

        if ($where !== null) {
            $sql .= " WHERE {$where}";
        }

        DB::statement($sql);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        if (! self::isPgsql()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    public static function i18nChecks(string $table, string ...$columns): void
    {
        foreach ($columns as $column) {
            self::check($table, "{$table}_{$column}_is_object", "jsonb_typeof({$column}) = 'object'");
        }
    }

    public static function fillfactor(string $table, int $percent): void
    {
        if (! self::isPgsql()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} SET (fillfactor = {$percent})");
    }
}
