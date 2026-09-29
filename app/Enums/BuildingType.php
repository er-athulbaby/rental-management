<?php

namespace App\Enums;

enum BuildingType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Mixed = 'mixed';
}
