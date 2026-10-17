<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\SaveVendor;
use App\Enums\Trade;
use App\Enums\VendorType;
use App\Livewire\Concerns\WithActor;
use App\Models\Vendor;
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
            'trades' => Trade::cases(),
            'types' => VendorType::cases(),
            'canEdit' => $vendor ? $this->actor()->can('update', $vendor) : true,
        ])->title($vendor ? $vendor->name : __('New vendor'));
    }
}
