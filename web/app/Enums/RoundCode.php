<?php

namespace App\Enums;

enum RoundCode: string
{
    case R64 = 'R64';
    case R32 = 'R32';
    case R16 = 'R16';
    case QuarterFinal = 'QF';
    case SemiFinal = 'SF';
    case ThirdPlace = 'THIRD';
    case Final = 'F';
    case Playoff = 'PO';
}
