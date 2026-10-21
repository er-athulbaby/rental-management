<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: paid payouts, the bank with the IBAN masked, and the leased rent schedule (plan ruling 12). */
#[Layout('layouts::portal')]
class Payments extends Component
{
    use InPortal;

    public function render(): View
    {
        $owner = $this->owner();

        return view('livewire.portal.owner.payments', [
            'payouts' => OwnerPortal::payouts($owner->id)->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'payables' => OwnerPortal::payables($owner->id)
                ->with(['contract' => fn ($q) => $q->with(['building' => fn ($b) => $b->withTrashed()])])
                ->orderBy('due_date')->orderBy('id')->get(),
            'bank' => filled($owner->iban)
                ? (filled($owner->bank_name)
                    ? __('Bank transfers are paid to :bank, IBAN :iban.', ['bank' => (string) $owner->bank_name, 'iban' => (string) $owner->maskedIban()])
                    : __('Bank transfers are paid to IBAN :iban.', ['iban' => (string) $owner->maskedIban()]))
                : null,
        ])->title(__('Payments'));
    }
}
