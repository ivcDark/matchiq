<?php

namespace App\Enums;

enum PredictionKind: string
{
    case PreMatch = 'pre_match';
    case Locked = 'locked';
}
