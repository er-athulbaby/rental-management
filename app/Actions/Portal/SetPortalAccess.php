<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: "Disable" / "Enable" on the tenant or owner page,
 * acting on the whole account (both sides). A disabled account's session ends on its next request (PortalSession);
 * enabling takes the page's record's current email. A no-op writes and audits nothing (owner portal plan ruling 6).
 */
final class SetPortalAccess
{
    public function handle(User $actor, Customer|Owner $record, bool $enabled): PortalAccount
    {
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }
        $column = PortalAccount::column($record);
        $tenant = $record instanceof Customer;

        return DB::transaction(function () use ($actor, $record, $enabled, $column, $tenant) {
            $account = PortalAccount::query()->where($column, $record->getKey())->lockForUpdate()->first();
            if ($account === null) {
                throw ValidationException::withMessages(['portal' => $tenant
                    ? __('This tenant has not been invited to the portal.')
                    : __('This owner has not been invited to the portal.')]);
            }
            if ($enabled === ($account->disabled_at === null)) {
                return $account;
            }

            if ($enabled) {
                $email = Str::lower(trim((string) $record->email));
                if ($email === '' || $record->trashed()) {
                    throw ValidationException::withMessages(['portal' => $tenant
                        ? __('Add the tenant\'s email before enabling the portal.')
                        : __('Add the owner\'s email before enabling the portal.')]);
                }
                if (PortalAccount::query()->where('email', $email)->whereKeyNot($account->id)->exists()) {
                    throw ValidationException::withMessages(['portal' => InviteToPortal::takenMessage($record)]);
                }
                $account->forceFill(['email' => $email, 'disabled_at' => null])->save();
            } else {
                $account->forceFill(['disabled_at' => now(), 'remember_token' => null])->save();
                PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete();
            }
            Audit::log($enabled ? 'portal.enabled' : 'portal.disabled', $account,
                properties: ['record' => $tenant ? 'tenant' : 'owner', $column => $record->getKey()], causer: $actor);

            return $account;
        });
    }
}
