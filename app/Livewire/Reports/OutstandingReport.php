<?php

namespace App\Livewire\Reports;

use App\Billing\CustomerCredit;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Reports\Queries;
use App\Support\Fils;
use Livewire\Component;

/** Spec §10: outstanding by customer = Σ invoice balances − customer credit. */
class OutstandingReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Outstanding by customer');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    protected function dateMode(): string
    {
        return 'none'; // live balances
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['customer' => __('Customer'), 'balance' => __('Invoice balances'), 'credit' => __('Credit'), 'outstanding' => __('Outstanding')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['balance', 'credit', 'outstanding'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $balances = Queries::openInvoices($this->actor(), $this->building)->selectRaw('customer_id, SUM(balance) AS total')->groupBy('customer_id')->pluck('total', 'customer_id');
        $customers = Customer::query()->visibleTo($this->actor())->when($this->building, fn ($q) => $q->whereIn('id', $balances->keys()))
            ->orderBy('name_en')->get(['id', 'name_en']);

        // ponytail: one credit query per customer; batch it if the customer list passes a few thousand.
        return array_values($customers->map(function (Customer $c) use ($balances) {
            $balance = Fils::fromDecimal((string) ($balances[$c->id] ?? '0'));
            $credit = CustomerCredit::fils($c->id);

            return $balance === 0 && $credit === 0 ? null : [
                '_url' => route('customers.statement', $c),
                'customer' => $c->name_en,
                'balance' => Fils::toDecimal($balance),
                'credit' => Fils::toDecimal($credit),
                'outstanding' => Fils::toDecimal($balance - $credit),
            ];
        })->filter()->values()->all());
    }
}
