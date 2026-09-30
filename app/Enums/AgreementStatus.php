<?php

namespace App\Enums;

/** Spec §5.4. */
enum AgreementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Expired = 'expired';
    case Renewed = 'renewed';
    case Closed = 'closed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
