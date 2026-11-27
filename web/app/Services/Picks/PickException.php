<?php

namespace App\Services\Picks;

use RuntimeException;

/** Нарушение правил выбора; сообщение безопасно показывать пользователю. */
class PickException extends RuntimeException
{
    public static function closed(): self
    {
        return new self('Выбор закрыт: матч уже начался или завершён.');
    }

    public static function unknownOutcome(): self
    {
        return new self('Это событие недоступно для выбора. Обновите страницу.');
    }
}
