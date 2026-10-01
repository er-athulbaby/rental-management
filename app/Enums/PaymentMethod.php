<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';               // only by clearing a cheque (spec §7.4)
    case Card = 'card';
    case DepositApplied = 'deposit_applied'; // only by deposit settlements (spec §7.7, M3b)

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    /** @return list<self> methods a person records on the payment form */
    public static function manual(): array
    {
        return [self::Cash, self::BankTransfer, self::Card];
    }
}
