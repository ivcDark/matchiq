<?php

namespace App\Enums;

enum PlayerPosition: string
{
    case Goalkeeper = 'GK';
    case Defender = 'DF';
    case Midfielder = 'MF';
    case Forward = 'FW';
}
