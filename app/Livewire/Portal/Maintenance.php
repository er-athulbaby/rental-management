<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's requests, whoever logged them, latest first. */
#[Layout('layouts::portal')]
class Maintenance extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.maintenance', [
            'requests' => MaintenanceRequest::query()->where('customer_id', $this->customerId())->with(['building', 'unit'])->latest('id')->get(),
        ])->title(__('Maintenance'));
    }
}
