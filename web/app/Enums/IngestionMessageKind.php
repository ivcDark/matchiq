<?php

namespace App\Enums;

/** Поле `kind` сообщения результата (collector → Laravel). */
enum IngestionMessageKind: string
{
    case Batch = 'batch';
    case Done = 'done';
    case Failed = 'failed';
}
