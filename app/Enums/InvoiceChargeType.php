<?php

namespace App\Enums;

enum InvoiceChargeType: string
{
    case Rent = 'rent';
    case ServiceCharge = 'service_charge';
    case Parking = 'parking';
    case Other = 'other';
    case Deposit = 'deposit';
    case Damage = 'damage';
    case Cleaning = 'cleaning';
    case Utilities = 'utilities';
    case OpeningBalance = 'opening_balance';

    /** @return list<self> charge types a person may put on a manual invoice (deposit and opening lines are system-made) */
    public static function manual(): array
    {
        return [self::Rent, self::ServiceCharge, self::Parking, self::Other, self::Damage, self::Cleaning, self::Utilities];
    }

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
