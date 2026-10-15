<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SavedReport;
use App\Models\User;
use App\Reports\Builder\Registry;

/** Spec §8: the creator or an Admin changes a report; anyone who may use its subject sees a shared one. */
class SavedReportPolicy
{
    public function view(User $user, SavedReport $report): bool
    {
        return isset(Registry::forUser($user)[$report->subject])
            && ($report->created_by === $user->id || $report->visibility === 'shared');
    }

    public function update(User $user, SavedReport $report): bool
    {
        return $report->created_by === $user->id || $user->can(PermissionName::SettingsManage);
    }

    public function delete(User $user, SavedReport $report): bool
    {
        return $this->update($user, $report);
    }
}
