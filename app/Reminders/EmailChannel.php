<?php

namespace App\Reminders;

use App\Mail\TenantReminder;
use App\Models\Customer;
use App\Models\ReminderSend;
use Illuminate\Support\Facades\Mail;

/** Email, to the address on the tenant record only (spec 2026-10-17 §9). */
final class EmailChannel implements Channel
{
    public function name(): string
    {
        return 'email';
    }

    /** Spec §5: mail is not configured while MAIL_MAILER is log or array. */
    public function ready(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function unavailableReason(Customer $tenant): ?string
    {
        return filled($tenant->email) ? null : ReminderSend::NO_EMAIL;
    }

    public function send(Customer $tenant, array $items): void
    {
        Mail::to((string) $tenant->email, $tenant->name_en)->send(new TenantReminder($tenant, $items));
    }
}
