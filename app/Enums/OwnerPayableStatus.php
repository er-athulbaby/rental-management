<?php

namespace App\Enums;

enum OwnerPayableStatus: string
{
    case Scheduled = 'scheduled';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
