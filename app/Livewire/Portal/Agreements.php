<?php

namespace App\Livewire\Portal;

use App\Enums\AgreementStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\Agreement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: the tenant's agreements past approval (plan ruling 11). */
#[Layout('layouts::portal')]
class Agreements extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.agreements', [
            'agreements' => Agreement::query()->where('customer_id', $this->customerId())
                ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
                ->with(['agreementUnits.unit' => fn ($q) => $q->withTrashed(), 'agreementUnits.unit.building' => fn ($q) => $q->withTrashed(), 'agreementUnits.charges'])
                ->orderByDesc('start_date')->orderByDesc('id')->get(),
        ])->title(__('Agreements'));
    }
}
