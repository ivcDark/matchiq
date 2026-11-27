<?php

namespace App\Services\Ingestion\Exceptions;

use InvalidArgumentException;

/** Прогон не нужен или уже идёт: для ручного запуска это ошибка, для расписания — штатный пропуск. */
class IngestionSkipped extends InvalidArgumentException {}
