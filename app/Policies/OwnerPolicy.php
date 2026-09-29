<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Owner;
use App\Models\User;

class OwnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function view(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    public function update(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    /** Spec §8.1: Finance views, Admin edits, Management none (disbursements.manage is Finance-only). */
    public function viewBank(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersBankManage) || $user->can(PermissionName::DisbursementsManage);
    }

    public function updateBank(User $user, Owner $owner): bool
    {
        return $user->can(PermissionName::OwnersBankManage);
    }
}
