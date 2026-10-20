<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's payments; a receipt for each confirmed one (plan ruling 10). */
#[Layout('layouts::portal')]
class Payments extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.payments', [
            'payments' => Payment::query()->where('customer_id', $this->customerId())->orderByDesc('received_on')->orderByDesc('id')->get(),
        ])->title(__('Payments'));
    }
}
