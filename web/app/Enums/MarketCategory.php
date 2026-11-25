<?php

namespace App\Enums;

enum MarketCategory: string
{
    case Results = 'results';
    case Totals = 'totals';
    case Goals = 'goals';
    case Corners = 'corners';
    case Yellows = 'yellows';
    case Reds = 'reds';
    case Handicap = 'handicap';
}
