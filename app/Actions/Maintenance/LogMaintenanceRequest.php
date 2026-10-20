<?php

namespace App\Actions\Maintenance;

use App\Actions\NextDocumentNumber;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\Trade;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-17 maintenance §4 step 1: log a request (maintenance.manage, in the user's buildings). */
final class LogMaintenanceRequest
{
    public function __construct(private NextDocumentNumber $next) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, array $data): MaintenanceRequest
    {
        if (! $actor->can('create', MaintenanceRequest::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $buildingId = is_numeric($data['building_id'] ?? null) ? (int) $data['building_id'] : 0;

        $v = Validator::make($data, [
            'building_id' => ['bail', 'required', 'integer', Rule::in(Building::visibleTo($actor)->pluck('id')->all())],
            'unit_id' => ['bail', 'nullable', 'integer', Rule::exists('units', 'id')->where('building_id', $buildingId)->whereNull('deleted_at')],
            'customer_id' => ['bail', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'reported_by_name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::enum(MaintenanceChannel::class)->except([MaintenanceChannel::Portal])],
            'category' => ['required', Rule::enum(Trade::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
        ])->validate();

        if (isset($v['customer_id']) && ! Customer::visibleTo($actor)->whereKey($v['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a tenant from the list.')]);
        }

        return DB::transaction(function () use ($actor, $v) {
            $request = new MaintenanceRequest([
                ...Arr::only($v, ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description']),
                'entry_permitted' => (bool) $v['entry_permitted'],
            ]);
            $request->forceFill([
                'number' => ($this->next)(NumberSequenceKey::MaintenanceRequest), 'status' => MaintenanceStatus::Open, 'created_by' => $actor->id,
            ])->save();
            MaintenanceEvent::record($request, $actor, to: MaintenanceStatus::Open->value);
            MaintenanceUpdate::sendFor($request, MaintenanceUpdate::RECEIVED); // spec §4 step 1; after commit

            return $request;
        });
    }
}
