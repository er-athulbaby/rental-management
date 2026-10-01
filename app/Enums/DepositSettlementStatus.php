<?php

namespace App\Enums;

enum DepositSettlementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Completed = 'completed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
