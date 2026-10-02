<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Building;
use App\Reports\Queries;
use Livewire\Component;

/** Spec §10: unit availability and occupancy by building, as at a date. Occupancy % = occupied ÷ (units − blocked). */
class OccupancyReport extends Component
{
    use ReportPage;

    protected function title(): string
    {
        return __('Unit availability and occupancy');
    }

    protected function permission(): bool
    {
        return $this->actor()->can('reports.operational');
    }

    protected function dateMode(): string
    {
        return 'single';
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['building' => __('Building'), 'units' => __('Units'), 'blocked' => __('Blocked'), 'occupied' => __('Occupied'), 'vacant' => __('Vacant'), 'occupancy' => __('Occupancy')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['units', 'blocked', 'occupied', 'vacant', 'occupancy'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $occupied = Queries::occupiedUnitIds($this->actor(), $this->to)->pluck('unit_id')->all();

        return array_values(Building::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->whereKey($b))->orderBy('code')
            ->with(['units:id,building_id,blocked'])->get()
            ->map(function (Building $b) use ($occupied) {
                $units = $b->units->count();
                $blocked = $b->units->where('blocked', true)->count();
                $taken = $b->units->whereIn('id', $occupied)->count();
                $available = $units - $blocked;

                return [
                    'building' => "{$b->code} — {$b->name}",
                    'units' => $units,
                    'blocked' => $blocked,
                    'occupied' => $taken,
                    'vacant' => max(0, $available - $taken),
                    'occupancy' => $available > 0 ? number_format(100 * $taken / $available, 1).'%' : '—',
                ];
            })->all());
    }
}
