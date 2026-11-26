<?php

namespace App\Enums;

enum UpsertOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
}
