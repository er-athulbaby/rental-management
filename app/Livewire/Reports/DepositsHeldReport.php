<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Unit;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Spec §10, §7.6: the deposit held per agreement unit is Σ movements, as at a date. */
class DepositsHeldReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Deposits held');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.financial');
    }

    protected function dateMode(): string
    {
        return 'single'; // "as at" is $to
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['unit' => __('Building / unit'), 'agreement' => __('Agreement'), 'customer' => __('Customer'), 'held' => __('Held')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['held'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $held = DB::table('deposit_movements as dm')
            ->join('agreement_units as au', 'au.id', '=', 'dm.agreement_unit_id')
            ->join('units as u', 'u.id', '=', 'au.unit_id')
            ->join('buildings as b', 'b.id', '=', 'u.building_id')
            ->join('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->join('customers as c', 'c.id', '=', 'a.customer_id')
            ->whereNull('a.deleted_at')
            ->where('dm.posted_at', '<=', $this->to.' 23:59:59')
            ->whereIn('u.id', Unit::query()->visibleTo($this->actor())->select('id'))
            ->when($this->building, fn ($q, int $b) => $q->where('u.building_id', $b))
            ->groupBy('dm.agreement_unit_id', 'b.code', 'u.code', 'a.number', 'c.name_en')
            ->havingRaw('SUM(dm.amount) > 0')
            ->orderBy('b.code')->orderBy('u.code')
            ->get(['b.code as building', 'u.code as unit', 'a.number as agreement', 'c.name_en as customer', DB::raw('SUM(dm.amount) as held')]);

        $total = 0;
        $rows = [];
        foreach ($held as $r) {
            $fils = Fils::fromDecimal((string) $r->held);
            $total += $fils;
            $rows[] = ['unit' => $r->building.' / '.$r->unit, 'agreement' => $r->agreement, 'customer' => $r->customer, 'held' => Fils::toDecimal($fils)];
        }
        if ($rows !== []) {
            $rows[] = ['unit' => __('Total'), 'agreement' => '', 'customer' => '', 'held' => Fils::toDecimal($total)];
        }

        return $rows;
    }
}
