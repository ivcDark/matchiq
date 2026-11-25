<?php

namespace App\Enums;

enum IngestionStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Failed = 'failed';
    case Partial = 'partial';
}
