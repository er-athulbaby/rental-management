<?php

namespace App\Actions\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 5: a request resolves itself when its work is done, or staff resolve it (ruling 4). */
final class ResolveMaintenanceRequest
{
    public function handle(User $actor, MaintenanceRequest $request): MaintenanceRequest
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $request) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['request' => __('This request is already closed.')]);
            }
            if ($locked->workOrders()->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress])->exists()) {
                throw ValidationException::withMessages(['request' => __('Complete or cancel its open work orders first.')]);
            }
            $this->resolve($actor, $locked);

            return $locked;
        });
    }

    /** Called inside a transaction with the request row locked, after one of its work orders closed. */
    public function ifDone(User $actor, MaintenanceRequest $locked): void
    {
        $statuses = $locked->workOrders()->toBase()->pluck('status')->all();
        $closed = array_diff($statuses, [WorkOrderStatus::Completed->value, WorkOrderStatus::Cancelled->value]) === [];

        if (! $locked->status->isClosed() && $statuses !== [] && $closed && in_array(WorkOrderStatus::Completed->value, $statuses, true)) {
            $this->resolve($actor, $locked);
        }
    }

    private function resolve(User $actor, MaintenanceRequest $locked): void
    {
        $from = $locked->status;
        $locked->forceFill(['status' => MaintenanceStatus::Resolved, 'resolved_at' => now()])->save();
        MaintenanceEvent::record($locked, $actor, from: $from->value, to: MaintenanceStatus::Resolved->value);
    }
}
