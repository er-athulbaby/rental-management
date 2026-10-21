<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\OwnerStatement;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: finalised statements with their figures and the staff PDF (plan ruling 11). */
#[Layout('layouts::portal')]
class Statements extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.statements', [
            'statements' => OwnerPortal::statements($this->ownerId())
                ->with(['contract' => fn ($q) => $q->with(['building' => fn ($b) => $b->withTrashed()])])
                ->orderByDesc('period_start')->orderByDesc('id')->get()
                ->map(fn (OwnerStatement $s) => ['statement' => $s, ...OwnerPortal::summary($s)]),
        ])->title(__('Statements'));
    }
}
