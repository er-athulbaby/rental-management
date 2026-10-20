<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Mail\PortalInvitation;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\User;
use App\Reminders\EmailChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 2026-10-18 tenant portal §3: "Invite to portal" and "Re-send invite". Creates or re-enables the tenant's account
 * with their current email, or re-sends to an enabled one, then queues the bilingual invite (plan ruling 7).
 */
final class InviteToPortal
{
    public function handle(User $actor, Customer $customer): PortalAccount
    {
        if (! $actor->can('update', $customer)) {
            throw new AuthorizationException;
        }
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['portal' => __('Portal email is not set up.')]);
        }
        $email = Str::lower(trim((string) $customer->email));
        if ($email === '' || $customer->trashed()) {
            throw ValidationException::withMessages(['portal' => __('Add the tenant\'s email before inviting them.')]);
        }
        if (PortalAccount::query()->where('email', $email)->where('customer_id', '!=', $customer->id)->exists()) {
            throw ValidationException::withMessages(['portal' => __('Another tenant\'s portal account uses this email.')]);
        }

        try {
            $account = DB::transaction(function () use ($actor, $customer, $email) {
                $account = PortalAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->first() ?? new PortalAccount;
                $resent = $account->exists && $account->disabled_at === null;
                $account->forceFill([
                    'customer_id' => $customer->id, 'email' => $email, 'disabled_at' => null, 'invited_at' => now(), 'invited_by' => $actor->id,
                ])->save();
                Audit::log('portal.invited', $account, properties: ['customer_id' => $customer->id, 'email' => $email, 'resent' => $resent], causer: $actor);

                return $account;
            });
        } catch (UniqueConstraintViolationException) { // a racing invite or save took this email
            throw ValidationException::withMessages(['portal' => __('Another tenant\'s portal account uses this email.')]);
        }

        try {
            Mail::to(new Address($email, $customer->name_en))->queue(new PortalInvitation($account));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['portal' => __('The invite could not be sent. Please try again.')]);
        }

        return $account;
    }
}
