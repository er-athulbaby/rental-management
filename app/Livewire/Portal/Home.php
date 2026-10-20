<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's home page. */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $customer = $this->account()->customer;

        return view('livewire.portal.home', [
            'name' => $customer->displayName(app()->getLocale()),
        ])->title(__('Home'));
    }
}
