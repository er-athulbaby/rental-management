<?php

namespace App\Enums;

enum AmendmentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
