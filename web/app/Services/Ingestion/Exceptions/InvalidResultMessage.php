<?php

namespace App\Services\Ingestion\Exceptions;

use RuntimeException;

/** Сообщение результата нарушает контракт: в DLQ без повторов. */
class InvalidResultMessage extends RuntimeException {}
