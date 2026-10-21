<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: one card per building of their contracts; the rent roll only while managed (plan ruling 9). */
#[Layout('layouts::portal')]
class Buildings extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.buildings', ['buildings' => OwnerPortal::buildings($this->ownerId())])->title(__('Buildings'));
    }
}
