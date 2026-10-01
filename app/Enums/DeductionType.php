<?php

namespace App\Enums;

enum DeductionType: string
{
    case Damage = 'damage';
    case Cleaning = 'cleaning';
    case Utilities = 'utilities';
    case UnpaidRent = 'unpaid_rent';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    /** The deductions-invoice line type (spec §7.7 step 1). Unpaid rent is never invoiced again. */
    public function chargeType(): InvoiceChargeType
    {
        return match ($this) {
            self::Damage => InvoiceChargeType::Damage,
            self::Cleaning => InvoiceChargeType::Cleaning,
            self::Utilities => InvoiceChargeType::Utilities,
            self::Other, self::UnpaidRent => InvoiceChargeType::Other,
        };
    }
}
