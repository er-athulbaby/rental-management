<?php

namespace App\Enums;

enum FeeType: string
{
    case PercentCollected = 'percent_collected';
    case PercentBilled = 'percent_billed';
    case Fixed = 'fixed'; // BHD per month
}
