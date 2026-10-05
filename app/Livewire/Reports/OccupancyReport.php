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
        $occupied = array_flip(Queries::occupiedUnitIds($this->actor(), $this->to)->pluck('unit_id')->all());

        return array_values(Building::visibleTo($this->actor())->when($this->building, fn ($q, $b) => $q->whereKey($b))->orderBy('code')
            ->with(['units:id,building_id,blocked'])->get()
            ->map(function (Building $b) use ($occupied) {
                $units = $b->units->count();
                $blocked = $b->units->where('blocked', true)->count();
                $taken = $b->units->filter(fn ($u) => isset($occupied[$u->id]))->count();
                $available = $units - $blocked;
                $takenAvailable = $b->units->filter(fn ($u) => ! $u->blocked && isset($occupied[$u->id]))->count(); // a blocked unit can't count towards the %

                return [
                    'building' => "{$b->code} — {$b->name}",
                    'units' => $units,
                    'blocked' => $blocked,
                    'occupied' => $taken,
                    'vacant' => $available - $takenAvailable,
                    'occupancy' => $available > 0 ? number_format(100 * $takenAvailable / $available, 1).'%' : '—',
                ];
            })->all());
    }

    /**
     * @param  list<array<string, string|int|null>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function charts(array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_map(fn ($r) => (int) $r[$key], $rows));
        $units = [
            ['label' => __('Occupied'), 'value' => $sum('units') - $sum('blocked') - $sum('vacant'), 'color' => '--viz-1'],
            ['label' => __('Vacant'), 'value' => $sum('vacant'), 'color' => '--viz-2'],
            ['label' => __('Blocked'), 'value' => $sum('blocked'), 'color' => '--viz-quiet'],
        ];

        return [
            ['type' => 'stack', 'title' => __('All units'), 'caption' => __(':n units', ['n' => $sum('units')]),
                'items' => array_map(fn ($u) => [...$u, 'display' => (string) $u['value']], $units)],
            ['type' => 'bars', 'title' => __('Occupancy by building'), 'max' => 100,
                'items' => array_map(fn ($r) => ['label' => $r['building'], 'value' => (float) $r['occupancy'], 'display' => (string) $r['occupancy']], $rows)],
        ];
    }
}
