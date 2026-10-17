<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\User;

/** Spec 2026-10-17 maintenance §6: maintenance.view to see, maintenance.manage to change; building scope for both. */
class MaintenanceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function view(User $user, MaintenanceRequest $request): bool
    {
        return $this->viewAny($user) && self::inScope($user, $request->building_id);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }

    /** Work orders, status changes, notes and photos. */
    public function update(User $user, MaintenanceRequest $request): bool
    {
        return $user->can(PermissionName::MaintenanceManage) && self::inScope($user, $request->building_id);
    }

    public static function inScope(User $user, int $buildingId): bool
    {
        return Building::visibleTo($user)->whereKey($buildingId)->exists();
    }
}
