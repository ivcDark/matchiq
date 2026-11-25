<?php

namespace App\Enums;

enum MatchedBy: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Alias = 'alias';
}
