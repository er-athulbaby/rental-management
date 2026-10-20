<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: one of the tenant's requests and its tenant timeline (plan ruling 15). Another tenant's id is a 404. */
#[Layout('layouts::portal')]
class MaintenanceShow extends Component
{
    use InPortal;

    #[Locked]
    public int $requestId;

    public function mount(string $maintenance): void
    {
        $this->requestId = MaintenanceRequest::query()->where('customer_id', $this->customerId())->findOrFail($maintenance)->id;
    }

    public function render(): View
    {
        $request = MaintenanceRequest::query()->where('customer_id', $this->customerId())->with(['building' => fn ($q) => $q->withTrashed(), 'unit' => fn ($q) => $q->withTrashed()])->findOrFail($this->requestId);

        return view('livewire.portal.maintenance-show', [
            'request' => $request,
            'timeline' => $request->tenantTimeline(),
        ])->title($request->number);
    }
}
