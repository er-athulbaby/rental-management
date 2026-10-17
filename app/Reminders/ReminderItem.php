<?php

namespace App\Reminders;

use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use LogicException;

/** One record due a reminder: its type, the record, and the rule day it matched (null for a manual send). */
final readonly class ReminderItem
{
    /** @param  'overdue'|'rent_due'|'cheque'|'renewal'  $type */
    public function __construct(public string $type, public Invoice|Cheque|Agreement $record, public ?int $ruleDay) {}

    /** The record's kind as stored in reminder_sends.item_type. */
    public function itemType(): string
    {
        return match (true) {
            $this->record instanceof Invoice => 'invoice',
            $this->record instanceof Cheque => 'cheque',
            default => 'agreement',
        };
    }

    public function tenant(): Customer
    {
        return $this->record->customer ?? throw new LogicException('A reminder item always has a tenant.');
    }
}
