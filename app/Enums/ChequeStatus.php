<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Held = 'held';
    case Issued = 'issued'; // an issued cheque not yet presented (spec §7.4)
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Replaced = 'replaced';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
