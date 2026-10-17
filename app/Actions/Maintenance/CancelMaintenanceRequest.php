<?php

namespace App\Actions\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 5: cancel with a reason; its open work orders are cancelled with it. */
final class CancelMaintenanceRequest
{
    public function handle(User $actor, MaintenanceRequest $request, string $reason): MaintenanceRequest
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for cancelling, up to 500 characters.')]);
        }

        return DB::transaction(function () use ($actor, $request, $reason) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['reason' => __('This request is already closed.')]);
            }

            $open = WorkOrder::query()->where('maintenance_request_id', $locked->id)
                ->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress])->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $workOrder) {
                $from = $workOrder->status;
                $workOrder->forceFill(['status' => WorkOrderStatus::Cancelled, 'cancel_reason' => $reason])->save();
                MaintenanceEvent::record($locked, $actor, $workOrder, $from->value, WorkOrderStatus::Cancelled->value, $reason);
            }

            $from = $locked->status;
            $locked->forceFill(['status' => MaintenanceStatus::Cancelled, 'cancel_reason' => $reason])->save();
            MaintenanceEvent::record($locked, $actor, null, $from->value, MaintenanceStatus::Cancelled->value, $reason);

            return $locked;
        });
    }
}
