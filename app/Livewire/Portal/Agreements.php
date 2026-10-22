<?php

namespace App\Livewire\Portal;

use App\CustomFields\CustomFields;
use App\Enums\AgreementStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\Agreement;
use App\Models\Unit;
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
        $agreements = Agreement::query()->where('customer_id', $this->customerId())
            ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
            ->with(['agreementUnits.unit' => fn ($q) => $q->withTrashed(), 'agreementUnits.unit.building' => fn ($q) => $q->withTrashed(), 'agreementUnits.charges'])
            ->orderByDesc('start_date')->orderByDesc('id')->get();

        // Spec 2026-10-21 custom fields §5: agreement and unit fields marked "Show in portal" (plan ruling 8).
        // ponytail: a few small queries per agreement and unit; batch them if a tenant ever has dozens.
        return view('livewire.portal.agreements', [
            'agreements' => $agreements,
            'details' => $agreements->mapWithKeys(fn (Agreement $a) => [$a->id => CustomFields::display($a, portal: true)])->all(),
            'unitDetails' => $agreements->flatMap(fn (Agreement $a) => $a->agreementUnits->pluck('unit'))->filter()->unique('id')
                ->mapWithKeys(fn (Unit $u) => [$u->id => CustomFields::display($u, portal: true)])->all(),
        ])->title(__('Agreements'));
    }
}
