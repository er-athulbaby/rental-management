<?php

namespace App\Enums;

enum DisbursementMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Cash = 'cash';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
