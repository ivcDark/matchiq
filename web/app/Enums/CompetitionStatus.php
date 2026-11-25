<?php

namespace App\Enums;

enum CompetitionStatus: string
{
    case Live = 'live';
    case Soon = 'soon';
    case Hidden = 'hidden';
}
