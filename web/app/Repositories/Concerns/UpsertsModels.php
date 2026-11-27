<?php

namespace App\Repositories\Concerns;

use App\Enums\UpsertOutcome;
use Illuminate\Database\Eloquent\Model;

trait UpsertsModels
{
    /**
     * Создаёт модель (`$onCreate` + `$attributes`) или обновляет только реально изменившиеся поля.
     * Без изменений `updated_at` не трогается.
     *
     * @return array{0: Model, 1: UpsertOutcome}
     */
    protected function persist(Model $model, ?Model $existing, array $attributes, array $onCreate = []): array
    {
        if ($existing === null) {
            $model->fill($onCreate + $attributes)->save();

            return [$model, UpsertOutcome::Created];
        }

        $existing->fill($attributes);
        $this->ignoreJsonKeyOrder($existing);

        if (! $existing->isDirty()) {
            return [$existing, UpsertOutcome::Unchanged];
        }

        $existing->save();

        return [$existing, UpsertOutcome::Updated];
    }

    /**
     * jsonb в PostgreSQL хранит ключи в своём порядке, а Eloquent сравнивает JSON-атрибуты строго:
     * без этого каждая повторная загрузка считала бы такие строки изменившимися.
     */
    private function ignoreJsonKeyOrder(Model $model): void
    {
        foreach (array_keys($model->getDirty()) as $key) {
            if (! $model->hasCast($key, ['array', 'json', 'collection'])) {
                continue;
            }

            $current = $model->getAttribute($key);
            $original = $model->getOriginal($key);
            if (is_array($current) && is_array($original) && $this->sameJson($current, $original)) {
                $model->syncOriginalAttribute($key);
            }
        }
    }

    private function sameJson(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! is_array($b)) {
            return $a === $b || (is_numeric($a) && is_numeric($b) && $a == $b);
        }
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $key => $value) {
            if (! array_key_exists($key, $b) || ! $this->sameJson($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }
}
