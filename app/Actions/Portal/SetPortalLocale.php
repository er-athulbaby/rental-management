<?php

namespace App\Actions\Portal;

use App\Models\PortalAccount;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-18 tenant portal §3: the account's language, en or ar. */
final class SetPortalLocale
{
    public function handle(PortalAccount $account, string $locale): void
    {
        if (! in_array($locale, PortalAccount::LOCALES, true)) {
            throw ValidationException::withMessages(['locale' => __('Choose English or Arabic.')]);
        }

        $account->forceFill(['locale' => $locale])->save();
    }
}
