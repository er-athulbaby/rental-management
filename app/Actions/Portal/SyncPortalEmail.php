<?php

namespace App\Actions\Portal;

use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-21 owner portal §3 "Email changes": the portal account follows the email of its tenant and its owner.
 * problem() runs in the save's validator; apply() inside the save's transaction (owner portal plan ruling 7).
 */
final class SyncPortalEmail
{
    /** Why $email (trimmed; null clears it) cannot be saved on $record, or null when it can. */
    public static function problem(Customer|Owner|null $record, ?string $email): ?string
    {
        $account = $record?->exists ? PortalAccount::of($record) : null;
        if ($record === null || $account === null) {
            return null;
        }
        $other = $account->otherThan($record);
        $otherEmail = $other !== null && filled($other->email) ? Str::lower((string) $other->email) : null;

        if ($email === null) {
            // The account keeps its address while the other record still has it.
            if ($account->disabled_at !== null || $otherEmail !== null) {
                return null;
            }

            return $record instanceof Customer
                ? __('This tenant uses the portal. Disable their portal access before removing the email.')
                : __('This owner uses the portal. Disable their portal access before removing the email.');
        }

        $email = Str::lower($email);
        if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
            return InviteToPortal::takenMessage($record);
        }
        if ($otherEmail !== null && $otherEmail !== $email) {
            return __('This email is also the portal sign-in for :name; change both together.', ['name' => $other->name_en]);
        }

        return null;
    }

    /** The account takes the record's new email in lower case; a new address ends remembered devices. */
    public static function apply(Customer|Owner $record): void
    {
        if (filled($record->email)) {
            $new = Str::lower((string) $record->email);
            PortalAccount::query()->where(PortalAccount::column($record), $record->getKey())->where('email', '!=', $new)
                ->update(['email' => $new, 'remember_token' => null]);
        }
    }
}
