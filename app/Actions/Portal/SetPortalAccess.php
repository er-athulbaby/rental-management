<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: "Disable" / "Enable" on the tenant page. A disabled account's session ends on its next
 * request (PortalSession); enabling takes the tenant's current email. A no-op writes and audits nothing (plan ruling 7).
 */
final class SetPortalAccess
{
    public function handle(User $actor, Customer $customer, bool $enabled): PortalAccount
    {
        if (! $actor->can('update', $customer)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $customer, $enabled) {
            $account = PortalAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($account === null) {
                throw ValidationException::withMessages(['portal' => __('This tenant has not been invited to the portal.')]);
            }
            if ($enabled === ($account->disabled_at === null)) {
                return $account;
            }

            if ($enabled) {
                $email = Str::lower(trim((string) $customer->email));
                if ($email === '' || $customer->trashed()) {
                    throw ValidationException::withMessages(['portal' => __('Add the tenant\'s email before enabling the portal.')]);
                }
                if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
                    throw ValidationException::withMessages(['portal' => __('Another tenant\'s portal account uses this email.')]);
                }
                $account->forceFill(['email' => $email, 'disabled_at' => null])->save();
            } else {
                $account->forceFill(['disabled_at' => now()])->save();
                PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete();
            }
            Audit::log($enabled ? 'portal.enabled' : 'portal.disabled', $account, properties: ['customer_id' => $customer->id], causer: $actor);

            return $account;
        });
    }
}
