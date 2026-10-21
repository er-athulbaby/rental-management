<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: requests in their buildings, latest first (plan rulings 9 and 14). */
#[Layout('layouts::portal')]
class Maintenance extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.maintenance', [
            'requests' => OwnerPortal::requests($this->ownerId())->with(['building', 'unit'])->latest('id')->get(),
        ])->title(__('Maintenance'));
    }
}
