<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

/** A work order follows its request (spec 2026-10-17 maintenance §6). */
class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('view', $workOrder->maintenanceRequest);
    }

    /** Status changes, completion and after-photos. */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('update', $workOrder->maintenanceRequest);
    }
}
