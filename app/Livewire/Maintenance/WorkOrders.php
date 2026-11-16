<?php

namespace App\Livewire\Maintenance;

use App\Enums\MaintenancePriority;
use App\Enums\Trade;
use App\Enums\WorkOrderStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: the requests list's filters plus status; "Overdue" when scheduled before today and still open. */
#[Title('Work orders')]
class WorkOrders extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $status = '';

    #[Url]
    public mixed $building = '';

    #[Url]
    public mixed $priority = '';

    #[Url]
    public mixed $category = '';

    #[Url]
    public mixed $vendor = '';

    #[Url]
    public mixed $search = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('maintenance.view'), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private static function idOf(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @return Builder<WorkOrder> every filter but the status: visibility, vendor, the request's building/priority/category, search */
    private function filtered(): Builder
    {
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        return WorkOrder::query()->visibleTo($this->actor())
            ->when(self::idOf($this->vendor), fn (Builder $q, int $v) => $q->where('vendor_id', $v))
            ->whereHas('maintenanceRequest', fn (Builder $r) => $r
                ->when(self::idOf($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
                ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
                ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('maintenanceRequest', fn (Builder $r) => $r->where('number', 'like', '%'.addcslashes($search, '%_\\').'%'))));
    }

    /**
     * Design pass §5: open (assigned or in progress) and overdue (open and scheduled before today, as WorkOrder::isOverdue).
     * One aggregate query over filtered().
     *
     * @return array{open: int, overdue: int}
     */
    private function stats(): array
    {
        $open = [WorkOrderStatus::Assigned->value, WorkOrderStatus::InProgress->value];
        $row = (array) ($this->filtered()->toBase()->selectRaw(
            'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS open_count, SUM(CASE WHEN status IN (?, ?) AND scheduled_on < ? THEN 1 ELSE 0 END) AS overdue_count',
            [...$open, ...$open, now('Asia/Bahrain')->toDateString()],
        )->first() ?? []);

        return ['open' => (int) ($row['open_count'] ?? 0), 'overdue' => (int) ($row['overdue_count'] ?? 0)];
    }

    public function render(): View
    {
        $status = WorkOrderStatus::tryFrom(is_string($this->status) ? $this->status : '');

        $workOrders = $this->filtered()
            ->when($status, fn (Builder $q, WorkOrderStatus $s) => $q->where('status', $s))
            ->with(['vendor:id,name', 'maintenanceRequest:id,number,building_id,unit_id,priority', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
            ->orderByRaw('scheduled_on IS NULL')->orderBy('scheduled_on')->orderBy('id')
            ->paginate(50);

        return view('livewire.maintenance.work-orders', [
            'workOrders' => $workOrders,
            'stats' => $this->stats(),
            'statuses' => WorkOrderStatus::cases(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
