<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\SaveVendor;
use App\Enums\ExpenseStatus;
use App\Enums\Trade;
use App\Enums\VendorType;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VendorForm extends Component
{
    use WithActor;

    #[Locked]
    public ?int $vendorId = null;

    // Untyped on purpose: a tampered payload reaches SaveVendor's validation instead of a TypeError (a 500).
    public mixed $form = ['type' => 'company', 'trades' => [], 'active' => true];

    public function mount(?Vendor $vendor = null): void
    {
        if ($vendor?->exists) {
            abort_unless($this->actor()->can('view', $vendor), 403);
            $this->vendorId = $vendor->id;
            $this->form = [...$vendor->only(['name', 'mobile', 'email', 'cr_number', 'vat_number', 'notes', 'trades', 'active']), 'type' => $vendor->type->value];
        } else {
            abort_unless($this->actor()->can('create', Vendor::class), 403);
        }
    }

    public function save(SaveVendor $save): void
    {
        try {
            $vendor = $save->handle($this->actor(), $this->vendorId ? Vendor::findOrFail($this->vendorId) : null, is_array($this->form) ? $this->form : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // "trades.0" belongs to the trades checkboxes.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.Str::before($k, '.') => $m])->all());
        }

        Flux::toast(variant: 'success', text: __('Vendor saved.'));
        $this->redirectRoute('maintenance.vendors.edit', $vendor, navigate: true);
    }

    public function render(): View
    {
        $vendor = $this->vendorId ? Vendor::findOrFail($this->vendorId) : null;

        return view('livewire.maintenance.vendor-form', [
            'vendor' => $vendor,
            'workOrders' => $vendor ? WorkOrder::query()->visibleTo($this->actor())->where('vendor_id', $vendor->id)
                ->with(['maintenanceRequest:id,number,building_id,unit_id', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
                ->latest('id')->limit(50)->get() : collect(),
            'totalCost' => $vendor ? Fils::toDecimal(Fils::fromDecimal((string) (Expense::query()
                ->join('work_orders', 'work_orders.expense_id', '=', 'expenses.id')
                ->where('work_orders.vendor_id', $vendor->id)
                ->where('expenses.status', ExpenseStatus::Recorded)
                ->whereIn('expenses.building_id', Building::query()->visibleTo($this->actor())->select('buildings.id'))
                ->sum('expenses.total') ?: '0'))) : null,
            'trades' => Trade::cases(),
            'types' => VendorType::cases(),
            'canEdit' => $vendor ? $this->actor()->can('update', $vendor) : true,
        ])->title($vendor ? $vendor->name : __('New vendor'));
    }
}
