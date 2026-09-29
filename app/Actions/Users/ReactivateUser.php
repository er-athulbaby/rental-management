<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ReactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('reactivate', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['active' => true])->save();

            Audit::log('user.reactivated', $user, causer: $actor);
        });
    }
}
