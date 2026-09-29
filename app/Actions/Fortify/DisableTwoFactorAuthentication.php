<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * Users holding a sensitive permission cannot switch off their own confirmed 2FA.
     * Abandoned (unconfirmed) setups can still be cleared, and an admin can reset someone else's.
     *
     * @param  mixed  $user
     */
    public function __invoke($user): void
    {
        abort_if(
            $user->is(auth()->user()) && $user->two_factor_confirmed_at !== null && $user->requiresTwoFactor(),
            403,
            __('Your role requires two-factor authentication.'),
        );

        parent::__invoke($user);
    }
}
