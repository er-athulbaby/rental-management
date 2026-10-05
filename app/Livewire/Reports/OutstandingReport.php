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
        return __('Outstanding by tenant');
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
        return ['customer' => __('Tenant'), 'balance' => __('Invoice balances'), 'credit' => __('Credit'), 'outstanding' => __('Outstanding')];
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

        $credits = CustomerCredit::filsFor($customers->map(fn (Customer $c) => $c->id)->all());

        return array_values($customers->map(function (Customer $c) use ($balances, $credits) {
            $balance = Fils::fromDecimal((string) ($balances[$c->id] ?? '0'));
            $credit = $credits[$c->id];

            return $balance === 0 && $credit === 0 ? null : [
                '_url' => route('customers.statement', $c),
                'customer' => $c->name_en,
                'balance' => Fils::toDecimal($balance),
                'credit' => Fils::toDecimal($credit),
                'outstanding' => Fils::toDecimal($balance - $credit),
            ];
        })->filter()->values()->all());
    }

    /**
     * @param  list<array<string, string|int|null>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function charts(array $rows): array
    {
        $owing = collect($rows)->filter(fn ($r) => (float) $r['outstanding'] > 0)->sortByDesc(fn ($r) => (float) $r['outstanding']);

        return [['type' => 'bars', 'title' => __('Who owes the most'), 'caption' => $owing->count() > 10 ? __('Top 10 of :n tenants', ['n' => $owing->count()]) : null,
            'items' => $owing->take(10)->map(fn ($r) => ['label' => $r['customer'], 'value' => (float) $r['outstanding'], 'display' => number_format((float) $r['outstanding'], 3), 'url' => $r['_url'] ?? null])->values()->all()]];
    }
}
