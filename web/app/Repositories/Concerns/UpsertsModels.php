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

        if (! $existing->isDirty()) {
            return [$existing, UpsertOutcome::Unchanged];
        }

        $existing->save();

        return [$existing, UpsertOutcome::Updated];
    }
}
