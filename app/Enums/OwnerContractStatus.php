<?php

namespace App\Enums;

enum OwnerContractStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Ended = 'ended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
