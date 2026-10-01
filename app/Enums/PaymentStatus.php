<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
}
