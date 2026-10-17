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

    public function render(): View
    {
        $status = WorkOrderStatus::tryFrom(is_string($this->status) ? $this->status : '');
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        $workOrders = WorkOrder::query()->visibleTo($this->actor())
            ->when($status, fn (Builder $q, WorkOrderStatus $s) => $q->where('status', $s))
            ->when(self::idOf($this->vendor), fn (Builder $q, int $v) => $q->where('vendor_id', $v))
            ->whereHas('maintenanceRequest', fn (Builder $r) => $r
                ->when(self::idOf($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
                ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
                ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('maintenanceRequest', fn (Builder $r) => $r->where('number', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->with(['vendor:id,name', 'maintenanceRequest:id,number,building_id,unit_id,priority', 'maintenanceRequest.building:id,code', 'maintenanceRequest.unit:id,code'])
            ->orderByRaw('scheduled_on IS NULL')->orderBy('scheduled_on')->orderBy('id')
            ->paginate(50);

        return view('livewire.maintenance.work-orders', [
            'workOrders' => $workOrders,
            'statuses' => WorkOrderStatus::cases(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
