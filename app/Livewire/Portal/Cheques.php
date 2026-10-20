<?php

namespace App\Livewire\Portal;

use App\Enums\ChequeDirection;
use App\Livewire\Concerns\InPortal;
use App\Models\Cheque;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the cheques the company received from the tenant (plan ruling 11). */
#[Layout('layouts::portal')]
class Cheques extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.cheques', [
            'cheques' => Cheque::query()->where('customer_id', $this->customerId())->where('direction', ChequeDirection::Received)
                ->orderByDesc('cheque_date')->orderByDesc('id')->get(),
        ])->title(__('Cheques'));
    }
}
