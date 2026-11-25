<?php

namespace App\Enums;

enum PickStatus: string
{
    case Pending = 'pending';
    case Won = 'won';
    case Lost = 'lost';
    case Void = 'void';
}
