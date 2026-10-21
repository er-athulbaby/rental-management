<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: the owner's home. */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.home', [
            'name' => $this->owner()->displayName(app()->getLocale()),
        ])->title(__('Home'));
    }
}
