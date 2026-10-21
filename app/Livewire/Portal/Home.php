<?php

namespace App\Livewire\Portal;

use App\Billing\CustomerCredit;
use App\Enums\AgreementStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\MaintenanceStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\Agreement;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: what the tenant owes, what is next, and what is open (plan ruling 12). */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $customer = $this->customer();
        $today = now('Asia/Bahrain');
        // The date a place is vacated is COALESCE(move_out_date, end_date), as portalPlaces and currentTenantId read it.
        $window = fn ($q) => $q->whereRaw('COALESCE(move_out_date, end_date) between ? and ?', [$today->toDateString(), $today->copy()->addDays(90)->toDateString()]);

        return view('livewire.portal.home', [
            'name' => $customer->displayName(app()->getLocale()),
            'owed' => Fils::grouped($customer->outstandingFils()),
            'credit' => Fils::grouped(CustomerCredit::fils($customer->id)),
            'nextDue' => Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)
                ->where('type', '!=', InvoiceType::CreditNote)->where('balance', '>', 0)->orderBy('due_date')->orderBy('id')->first(),
            'cheques' => Cheque::query()->where('customer_id', $customer->id)->where('direction', ChequeDirection::Received)
                ->where('status', ChequeStatus::Held)->where('cheque_date', '>=', $today->toDateString())->orderBy('cheque_date')->orderBy('id')->limit(5)->get(),
            'requests' => MaintenanceRequest::query()->where('customer_id', $customer->id)
                ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])->latest('id')->limit(5)->get(),
            'ending' => Agreement::query()->where('customer_id', $customer->id)->where('status', AgreementStatus::Active)
                ->whereHas('agreementUnits', $window)->with(['agreementUnits' => $window])->get()
                ->each(fn (Agreement $a) => $a->setAttribute('ends_on', $a->agreementUnits->map(fn ($au) => $au->move_out_date ?? $au->end_date)->min()))
                ->sortBy([['ends_on', 'asc'], ['id', 'asc']])->values(),
        ])->title(__('Home'));
    }
}
