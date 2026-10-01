<?php

namespace App\Enums;

enum DisbursementPurpose: string
{
    case OwnerRemittance = 'owner_remittance';
    case HeadLease = 'head_lease';
    case DepositRefund = 'deposit_refund';
    case CreditRefund = 'credit_refund';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
