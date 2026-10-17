<?php

namespace App\Livewire\Maintenance;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\Trade;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\MaintenanceRequest;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: tabs with counts, filters and search; urgent first, then oldest (plan ruling 14). */
#[Title('Maintenance requests')]
class Requests extends Component
{
    use WithActor, WithPagination;

    public const array TABS = ['open', 'in_progress', 'resolved', 'all'];

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $tab = 'open';

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
        abort_unless($this->actor()->can('viewAny', MaintenanceRequest::class), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    /** "12" → 12; anything else → null. */
    private static function idOf(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @return Builder<MaintenanceRequest> every filter except the tab, shared by each tab and its count */
    private function filtered(): Builder
    {
        $priority = MaintenancePriority::tryFrom(is_string($this->priority) ? $this->priority : '');
        $category = Trade::tryFrom(is_string($this->category) ? $this->category : '');
        $search = is_string($this->search) ? trim($this->search) : '';

        return MaintenanceRequest::query()->visibleTo($this->actor())
            ->when(self::idOf($this->building), fn (Builder $q, int $b) => $q->inBuilding($b))
            ->when($priority, fn (Builder $q, MaintenancePriority $p) => $q->where('priority', $p))
            ->when($category, fn (Builder $q, Trade $c) => $q->where('category', $c))
            ->when(self::idOf($this->vendor), fn (Builder $q, int $v) => $q->whereHas('workOrders', fn (Builder $w) => $w->where('vendor_id', $v)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereHas('unit', fn (Builder $u) => $u->where('code', $search))
                ->orWhereHas('customer', fn (Builder $c) => $c->search($search))));
    }

    /**
     * @param  Builder<MaintenanceRequest>  $query
     * @return Builder<MaintenanceRequest>
     */
    private static function inTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'open' => $query->where('status', MaintenanceStatus::Open),
            'in_progress' => $query->where('status', MaintenanceStatus::InProgress),
            'resolved' => $query->where('status', MaintenanceStatus::Resolved),
            default => $query,
        };
    }

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'open';

        return view('livewire.maintenance.requests', [
            'requests' => self::inTab($this->filtered(), $tab)->with(['building:id,code,name', 'unit:id,code', 'customer:id,name_en'])->urgentFirst()->paginate(50),
            'currentTab' => $tab,
            'tabLabels' => ['open' => __('Open'), 'in_progress' => __('In progress'), 'resolved' => __('Resolved'), 'all' => __('All')],
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => self::inTab($this->filtered(), $t)->count()])->all(),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'priorities' => MaintenancePriority::cases(),
            'categories' => Trade::cases(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
