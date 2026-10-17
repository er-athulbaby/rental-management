<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\ReportPackage;
use App\Models\User;

/** Spec 2026-10-17 §3: the creator or settings.manage changes a package; anyone with report access sees a shared one. */
class ReportPackagePolicy
{
    public function view(User $user, ReportPackage $package): bool
    {
        return $this->reports($user) && ($package->created_by === $user->id || $package->visibility === 'shared');
    }

    public function update(User $user, ReportPackage $package): bool
    {
        return $this->reports($user) && ($package->created_by === $user->id || $user->can(PermissionName::SettingsManage));
    }

    public function delete(User $user, ReportPackage $package): bool
    {
        return $this->update($user, $package);
    }

    private function reports(User $user): bool
    {
        return $user->can(PermissionName::ReportsOperational) || $user->can(PermissionName::ReportsFinancial);
    }
}
