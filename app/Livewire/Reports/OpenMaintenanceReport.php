<?php

namespace App\Livewire\Reports;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderStatus;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Reports\Packages\PackageReport;
use Livewire\Component;

/** Spec 2026-10-17 maintenance §5: requests still open or in progress, urgent first, then oldest, with their age and vendors. */
class OpenMaintenanceReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Open maintenance');
    }

    /** Plan ruling 16: like the cheque reports, this one also needs its records' permission. */
    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && $user->can('maintenance.view');
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['number' => __('Request'), 'building' => __('Building'), 'unit' => __('Unit'), 'category' => __('Category'),
            'priority' => __('Priority'), 'age' => __('Age (days)'), 'vendor' => __('Vendor')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['age'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $today = now('Asia/Bahrain')->startOfDay();

        return array_values(MaintenanceRequest::query()->visibleTo($this->actor())
            ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->with(['building:id,code', 'unit:id,code', 'workOrders' => fn ($q) => $q->whereIn('status', [WorkOrderStatus::Assigned, WorkOrderStatus::InProgress]), 'workOrders.vendor:id,name'])
            ->urgentFirst()->get()
            ->map(fn (MaintenanceRequest $r) => [
                '_url' => route('maintenance.requests.show', $r),
                'number' => $r->number,
                'building' => $r->building->code,
                'unit' => data_get($r, 'unit.code', __('Common area')),
                'category' => $r->category->label(),
                'priority' => $r->priority->label(),
                'age' => (int) $r->created_at->timezone('Asia/Bahrain')->startOfDay()->diffInDays($today),
                'vendor' => $r->workOrders->map(fn (WorkOrder $w) => $w->vendor->name)->unique()->implode(', '),
            ])->all());
    }
}
