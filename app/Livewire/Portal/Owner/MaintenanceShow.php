<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: one request in their buildings, the tenant timeline and the cost charged to them. Any other id is a 404. */
#[Layout('layouts::portal')]
class MaintenanceShow extends Component
{
    use InPortal;

    #[Locked]
    public int $requestId;

    public function mount(string $maintenance): void
    {
        $this->requestId = OwnerPortal::requests($this->ownerId())->findOrFail($maintenance)->id;
    }

    public function render(): View
    {
        $ownerId = $this->ownerId();
        $request = OwnerPortal::requests($ownerId)->with(['building', 'unit'])->findOrFail($this->requestId);

        return view('livewire.portal.owner.maintenance-show', [
            'request' => $request,
            'timeline' => $request->tenantTimeline(),
            'cost' => OwnerPortal::costFils($request, $ownerId),
        ])->title($request->number);
    }
}
