<?php

namespace App\Enums;

/** Spec §6.1. "Partially paid", "Paid" and "Overdue" are derived labels, never stored. */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
