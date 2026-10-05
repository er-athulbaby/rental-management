<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Customer;
use App\Reports\Queries;
use App\Support\Fils;
use Livewire\Component;

/** Spec §10: overdue balances by days past grace_until — 1–30 / 31–60 / 61–90 / 91+. */
class AgeingReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Overdue ageing');
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
        return ['customer' => __('Tenant'), 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd91' => '91+', 'total' => __('Total')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['d30', 'd60', 'd90', 'd91', 'total'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();
        $byCustomer = [];
        foreach (Queries::overdueInvoices($this->actor(), $this->building)->get(['customer_id', 'balance', 'grace_until']) as $invoice) {
            if ($invoice->grace_until === null) {
                continue; // never: overdueInvoices() filters on grace_until
            }
            $days = (int) $invoice->grace_until->startOfDay()->diffInDays($today);
            $bucket = match (true) {
                $days <= 30 => 'd30',
                $days <= 60 => 'd60',
                $days <= 90 => 'd90',
                default => 'd91',
            };
            $byCustomer[$invoice->customer_id][$bucket] = ($byCustomer[$invoice->customer_id][$bucket] ?? 0) + Fils::fromDecimal((string) $invoice->balance);
        }

        $names = Customer::query()->whereIn('id', array_keys($byCustomer))->orderBy('name_en')->pluck('name_en', 'id');

        return array_values($names->map(function (string $name, int $id) use ($byCustomer) {
            $b = $byCustomer[$id];

            return [
                '_url' => route('customers.statement', $id),
                'customer' => $name,
                'd30' => Fils::toDecimal($b['d30'] ?? 0),
                'd60' => Fils::toDecimal($b['d60'] ?? 0),
                'd90' => Fils::toDecimal($b['d90'] ?? 0),
                'd91' => Fils::toDecimal($b['d91'] ?? 0),
                'total' => Fils::toDecimal(array_sum($b)),
            ];
        })->values()->all());
    }

    /**
     * @param  list<array<string, string|int|null>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function charts(array $rows): array
    {
        $buckets = ['d30' => ['1–30 days', '--viz-age-1'], 'd60' => ['31–60 days', '--viz-age-2'], 'd90' => ['61–90 days', '--viz-age-3'], 'd91' => ['Over 90 days', '--viz-age-4']];
        $sum = fn (string $key) => array_sum(array_map(fn ($r) => (float) $r[$key], $rows));
        $top = collect($rows)->sortByDesc(fn ($r) => (float) $r['total'])->take(8);

        return [
            ['type' => 'stack', 'title' => __('Overdue by age'), 'caption' => __(':n BHD in total', ['n' => number_format($sum('total'), 3)]),
                'items' => array_map(fn ($key, $b) => ['label' => __($b[0]), 'value' => $sum($key), 'display' => number_format($sum($key), 3), 'color' => $b[1]], array_keys($buckets), $buckets)],
            ['type' => 'bars', 'title' => __('Largest overdue balances'), 'caption' => count($rows) > 8 ? __('Top 8 of :n tenants', ['n' => count($rows)]) : null,
                'items' => $top->map(fn ($r) => ['label' => $r['customer'], 'value' => (float) $r['total'], 'display' => number_format((float) $r['total'], 3), 'url' => $r['_url'] ?? null])->values()->all()],
        ];
    }
}
