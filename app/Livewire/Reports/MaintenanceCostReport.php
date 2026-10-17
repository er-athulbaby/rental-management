<?php

namespace App\Livewire\Reports;

use App\Enums\ChargeTo;
use App\Enums\ExpenseStatus;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\CompanySetting;
use App\Models\WorkOrder;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Spec 2026-10-17 maintenance §5 (plan ruling 16): what maintenance cost, from the recorded expenses linked to work
 * orders, by expense date. Company and tenant charges use Building profitability's rule (net when VAT-registered,
 * else total), so they agree with it; owner charges are shown apart at their total, as owner statements charge them.
 */
class MaintenanceCostReport extends Component implements PackageReport
{
    use ReportPage;

    // Untyped on purpose: a tampered value reaches the filter validation instead of a TypeError (a 500).
    #[Url]
    public mixed $group = 'building';

    protected function title(): string
    {
        return __('Maintenance cost');
    }

    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && $user->can('maintenance.view');
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['group' => ['building' => __('By building'), 'unit' => __('By unit'), 'category' => __('By category')]];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return [
            'group' => match ($this->group) {
                'unit' => __('Unit'),
                'category' => __('Category'),
                default => __('Building'),
            },
            'orders' => __('Work orders'),
            'cost' => __('Cost: company and tenant'),
            'owner' => __('Charged to owners'),
        ];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['orders', 'cost', 'owner'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $net = CompanySetting::current()->vat_registered;
        // ponytail: grouped in PHP over one company's work orders; move to SQL GROUP BY if it ever runs to many thousands.
        $workOrders = WorkOrder::query()->visibleTo($this->actor())
            ->whereHas('expense', fn ($e) => $e->where('status', ExpenseStatus::Recorded)->whereBetween('expense_date', [$this->from, $this->to])
                ->when($this->building, fn ($q, $b) => $q->where('building_id', $b)))
            ->with(['expense', 'expense.building:id,code', 'expense.unit:id,code', 'maintenanceRequest:id,category'])
            ->get();

        $groups = [];
        foreach ($workOrders as $wo) {
            $e = $wo->expense;
            if ($e === null) {
                continue;
            }
            [$key, $label] = match ($this->group) {
                'unit' => [$e->building_id.'/'.$e->unit_id, data_get($e, 'building.code').'/'.data_get($e, 'unit.code', __('Common area'))],
                'category' => [$wo->maintenanceRequest->category->value, $wo->maintenanceRequest->category->label()],
                default => [(string) $e->building_id, (string) data_get($e, 'building.code')],
            };
            $groups[$key] ??= ['group' => $label, 'orders' => 0, 'cost' => 0, 'owner' => 0];
            $groups[$key]['orders']++;
            if ($e->charge_to === ChargeTo::Owner) {
                $groups[$key]['owner'] += Fils::fromDecimal($e->total);
            } else {
                $groups[$key]['cost'] += Fils::fromDecimal($net ? $e->net : $e->total);
            }
        }
        if ($groups === []) {
            return [];
        }
        usort($groups, fn (array $x, array $y) => strcmp($x['group'], $y['group']));
        $money = fn (array $g) => [...$g, 'cost' => Fils::toDecimal($g['cost']), 'owner' => Fils::toDecimal($g['owner'])];

        return [...array_map($money, $groups), $money(['_kind' => 'total', 'group' => __('Total'), 'orders' => array_sum(array_column($groups, 'orders')),
            'cost' => array_sum(array_column($groups, 'cost')), 'owner' => array_sum(array_column($groups, 'owner'))])];
    }
}
