<?php

namespace App\Enums;

enum SourceKind: string
{
    case Scraper = 'scraper';
    case Api = 'api';
    case Manual = 'manual';
}
