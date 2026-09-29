<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('deactivate', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['active' => false])->save();
            $user->logoutEverywhere();

            Audit::log('user.deactivated', $user, causer: $actor);
        });
    }
}
