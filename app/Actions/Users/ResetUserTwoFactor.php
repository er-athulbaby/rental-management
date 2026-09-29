<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

final class ResetUserTwoFactor
{
    public function __construct(private DisableTwoFactorAuthentication $disable) {}

    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('resetTwoFactor', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            ($this->disable)($user); // resolves to App\Actions\Fortify\DisableTwoFactorAuthentication (Task 5)
            $user->logoutEverywhere();

            Audit::log('user.2fa.reset', $user, causer: $actor);
        });
    }
}
