<?php

namespace App\Enums;

enum AbsenceType: string
{
    case Injury = 'injury';
    case Suspension = 'suspension';
    case Doubtful = 'doubtful';
}
