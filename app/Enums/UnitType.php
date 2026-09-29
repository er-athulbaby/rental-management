<?php

namespace App\Enums;

enum UnitType: string
{
    case Flat = 'flat';
    case Villa = 'villa';
    case Studio = 'studio';
    case Shop = 'shop';
    case Office = 'office';
    case Showroom = 'showroom';
    case Warehouse = 'warehouse';
    case Other = 'other';
}
