<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: expenses charged to the owner on their contracts; reversed ones marked; no internal notes. */
#[Layout('layouts::portal')]
class Expenses extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.expenses', [
            'expenses' => OwnerPortal::expenses($this->ownerId())
                ->with(['building' => fn ($q) => $q->withTrashed(), 'unit' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('expense_date')->orderByDesc('id')->get(),
        ])->title(__('Expenses'));
    }
}
