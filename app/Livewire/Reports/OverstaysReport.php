<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Agreement;
use App\Reports\Queries;
use Livewire\Component;

/** Spec §10: expired agreements not closed — still holding a unit. */
class OverstaysReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Overstays: expired, not closed');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['number' => __('Agreement'), 'customer' => __('Tenant'), 'units' => __('Units'), 'end' => __('Ended'), 'days' => __('Days past end')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['days'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();

        return array_values(Queries::overstays($this->actor(), $this->building)
            ->with(['customer:id,name_en', 'agreementUnits.unit:id,code,building_id', 'agreementUnits.unit.building:id,code'])
            ->orderBy('end_date')->orderBy('id')->get()
            ->map(fn (Agreement $a) => [
                '_url' => route('agreements.show', $a),
                'number' => $a->number,
                'customer' => $a->customer->name_en,
                'units' => $a->agreementUnits->map(fn ($au) => $au->unit->building->code.'/'.$au->unit->code)->implode(', '),
                'end' => $a->end_date->format('d/m/Y'),
                'days' => (int) $a->end_date->diffInDays($today, true),
            ])->all());
    }
}
