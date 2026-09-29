<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\User;

/** Spec §8.1 (owners.view / owners.manage) within the building scope of §8.2. */
class OwnerContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::OwnersView);
    }

    public function view(User $user, OwnerContract $contract): bool
    {
        return $user->can(PermissionName::OwnersView) && self::inScope($user, $contract);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::OwnersManage);
    }

    public function update(User $user, OwnerContract $contract): bool
    {
        return $user->can(PermissionName::OwnersManage) && self::inScope($user, $contract);
    }

    private static function inScope(User $user, OwnerContract $contract): bool
    {
        return Building::visibleTo($user)->whereKey($contract->building_id)->exists();
    }
}
