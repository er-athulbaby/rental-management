<?php

namespace App\Actions\Maintenance;

use App\Actions\NextDocumentNumber;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 2: a work order to an active vendor doing the request's trade (or general). */
final class AddWorkOrder
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, MaintenanceRequest $request, array $data): WorkOrder
    {
        if (! $actor->can('update', $request)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);
        $v = Validator::make($data, [
            'vendor_id' => ['bail', 'required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'scheduled_on' => ['nullable', 'date_format:Y-m-d'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'estimate' => ['nullable', Fils::rule()],
        ])->validate();

        $vendor = Vendor::query()->findOrFail((int) $v['vendor_id']);
        if (! $vendor->active) {
            throw ValidationException::withMessages(['vendor_id' => __('This vendor is inactive and cannot get new work orders.')]);
        }
        if (! $vendor->handles($request->category)) {
            throw ValidationException::withMessages(['vendor_id' => __('This vendor does not do :trade work.', ['trade' => mb_strtolower($request->category->label())])]);
        }

        return DB::transaction(function () use ($actor, $request, $v) {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status->isClosed()) {
                throw ValidationException::withMessages(['vendor_id' => __('This request is closed.')]);
            }

            $workOrder = new WorkOrder([
                'vendor_id' => $v['vendor_id'],
                'scheduled_on' => $v['scheduled_on'] ?? null,
                'instructions' => $v['instructions'] ?? null,
                'estimate' => isset($v['estimate']) ? Fils::toDecimal(Fils::fromDecimal((string) $v['estimate'])) : null,
            ]);
            $workOrder->forceFill([
                'maintenance_request_id' => $locked->id, 'number' => ($this->next)(NumberSequenceKey::WorkOrder),
                'status' => WorkOrderStatus::Assigned, 'created_by' => $actor->id,
            ])->save();
            MaintenanceEvent::record($locked, $actor, $workOrder, to: WorkOrderStatus::Assigned->value);

            if ($locked->status === MaintenanceStatus::Open) { // spec §4 step 2: the first work order starts the request
                $locked->forceFill(['status' => MaintenanceStatus::InProgress])->save();
                MaintenanceEvent::record($locked, $actor, from: MaintenanceStatus::Open->value, to: MaintenanceStatus::InProgress->value);
            }

            return $workOrder;
        });
    }
}
