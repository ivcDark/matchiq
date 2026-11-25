<?php

namespace App\Enums;

enum IngestionPhase: string
{
    case Fetch = 'fetch';
    case Parse = 'parse';
    case Normalize = 'normalize';
    case Match = 'match';
    case Upsert = 'upsert';
}
