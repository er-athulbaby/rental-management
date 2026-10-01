<?php

namespace App\Enums;

enum DisbursementStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
