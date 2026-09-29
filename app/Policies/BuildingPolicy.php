<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\User;

class BuildingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BuildingsView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BuildingsManage);
    }

    public function view(User $user, Building $building): bool
    {
        return $user->can(PermissionName::BuildingsView) && self::inScope($user, $building);
    }

    public function update(User $user, Building $building): bool
    {
        return $user->can(PermissionName::BuildingsManage) && self::inScope($user, $building);
    }

    private static function inScope(User $user, Building $building): bool
    {
        return Building::visibleTo($user)->whereKey($building->getKey())->exists();
    }
}
