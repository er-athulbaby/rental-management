<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\Vendor;

/** Spec 2026-10-17 maintenance §5-§6: vendors are company-wide; maintenance.manage edits them. */
class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->can(PermissionName::MaintenanceView);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->can(PermissionName::MaintenanceManage);
    }
}
