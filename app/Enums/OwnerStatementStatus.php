<?php

namespace App\Enums;

enum OwnerStatementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Finalised = 'finalised';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
