<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Rent = 'rent';
    case Deposit = 'deposit';
    case Manual = 'manual';
    case Opening = 'opening';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
