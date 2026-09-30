<?php

namespace App\Enums;

/** Recurring agreement charges (spec §5.3). */
enum ChargeType: string
{
    case Rent = 'rent';
    case ServiceCharge = 'service_charge';
    case Parking = 'parking';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
