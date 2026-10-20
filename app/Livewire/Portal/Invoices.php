<?php

namespace App\Livewire\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\InPortal;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: issued invoices, credit notes left out, in tabs Unpaid / Paid / All (plan ruling 11). */
#[Layout('layouts::portal')]
class Invoices extends Component
{
    use InPortal;

    private const array TABS = ['unpaid', 'paid', 'all'];

    #[Url]
    public mixed $tab = 'unpaid'; // untyped: a tampered value falls back to Unpaid

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'unpaid';

        return view('livewire.portal.invoices', [
            'currentTab' => $tab,
            'tabs' => ['unpaid' => __('Unpaid'), 'paid' => __('Paid'), 'all' => __('All')],
            'invoices' => Invoice::query()->where('customer_id', $this->customerId())
                ->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
                ->when($tab === 'unpaid', fn ($q) => $q->where('balance', '>', 0))
                ->when($tab === 'paid', fn ($q) => $q->where('balance', '<=', 0))
                ->orderByDesc('due_date')->orderByDesc('id')->get(),
        ])->title(__('Invoices'));
    }
}
