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

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
