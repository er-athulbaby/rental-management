<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\OwnerContract;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: their contracts — number, building, type, dates, status (plan ruling 8). */
#[Layout('layouts::portal')]
class Contracts extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.contracts', [
            'contracts' => OwnerContract::query()->portalFor($this->ownerId())->with(['building' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('start_date')->orderByDesc('id')->get(),
        ])->title(__('Contracts'));
    }
}
