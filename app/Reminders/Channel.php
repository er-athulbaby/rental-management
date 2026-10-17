<?php

namespace App\Reminders;

use App\Models\Customer;

/**
 * Spec 2026-10-17 §7: how a combined reminder reaches a tenant. Email now. A WhatsApp channel later implements the same
 * four methods, so the rules, the log and the screens do not change.
 */
interface Channel
{
    /** Stored in reminder_sends.channel. */
    public function name(): string;

    /** False while the channel cannot send at all; nothing is sent then. */
    public function ready(): bool;

    /** Why this tenant cannot be reached on this channel (a ReminderSend reason code), or null. */
    public function unavailableReason(Customer $tenant): ?string;

    /**
     * Sends one message with every item. Throws when it could not be sent.
     *
     * @param  list<ReminderItem>  $items
     */
    public function send(Customer $tenant, array $items): void;
}
