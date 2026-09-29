<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::UsersManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::UsersManage);
    }

    /** Vendor Support can't be edited by Admin (spec §8.1). */
    public function update(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $user->isVendorSupport();
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $actor->is($user) && $user->active;
    }

    /** Vendor Support is re-enabled only with rms:vendor-support on the server. */
    public function reactivate(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $user->isVendorSupport() && ! $user->active;
    }

    public function resetTwoFactor(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $actor->is($user) && ! $user->isVendorSupport();
    }
}
