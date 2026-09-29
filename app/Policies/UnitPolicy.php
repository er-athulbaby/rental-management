<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Unit;
use App\Models\User;

/** Units inherit their building's rules (spec §8.2). */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::BuildingsView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::BuildingsManage);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->can('view', $unit->building);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->can('update', $unit->building);
    }
}
