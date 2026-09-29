<?php

namespace App\Enums;

enum ChargeTo: string
{
    case Company = 'company';
    case Owner = 'owner';
    case Tenant = 'tenant'; // M3: creates a manual invoice (spec §4.7)
}
