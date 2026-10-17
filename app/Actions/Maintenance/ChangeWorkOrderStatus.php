<?php

namespace App\Actions\Maintenance;

use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 3: start a work order, or cancel it with a reason. Completing is CompleteWorkOrder. */
final class ChangeWorkOrderStatus
{
    public function __construct(private ResolveMaintenanceRequest $resolve) {}

    public function handle(User $actor, WorkOrder $workOrder, string $to, ?string $reason = null): WorkOrder
    {
        if (! $actor->can('update', $workOrder)) {
            throw new AuthorizationException;
        }

        $target = WorkOrderStatus::tryFrom($to);
        if ($target !== WorkOrderStatus::InProgress && $target !== WorkOrderStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => __('A work order can only be started or cancelled here.')]);
        }
        $reason = trim((string) $reason);
        if ($target === WorkOrderStatus::Cancelled && ($reason === '' || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['reason' => __('Give a reason for cancelling, up to 500 characters.')]);
        }

        return DB::transaction(function () use ($actor, $workOrder, $target, $reason) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($workOrder->maintenance_request_id);
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $from = $locked->status;
            $allowed = $target === WorkOrderStatus::InProgress ? $from === WorkOrderStatus::Assigned : $from->isOpen();
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => __('This work order is :status.', ['status' => mb_strtolower($from->label())])]);
            }

            $cancelled = $target === WorkOrderStatus::Cancelled;
            $locked->forceFill(['status' => $target, 'cancel_reason' => $cancelled ? $reason : null])->save();
            MaintenanceEvent::record($request, $actor, $locked, $from->value, $target->value, $cancelled ? $reason : null);
            $this->resolve->ifDone($actor, $request);

            return $locked;
        });
    }
}
