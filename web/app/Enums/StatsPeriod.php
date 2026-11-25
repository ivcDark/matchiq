<?php

namespace App\Enums;

enum StatsPeriod: string
{
    case FullTime = 'full_time';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';
}
