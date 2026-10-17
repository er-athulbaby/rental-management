<?php

namespace App\Livewire\Maintenance;

use App\Enums\ExpenseStatus;
use App\Enums\Trade;
use App\Enums\VendorType;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Spec 2026-10-17 maintenance §5: vendors, filtered by trade, type and active. */
#[Title('Vendors')]
class Vendors extends Component
{
    use WithActor, WithPagination;

    // Untyped on purpose: a tampered value means "no filter" instead of a TypeError (a 500).
    #[Url]
    public mixed $trade = '';

    #[Url]
    public mixed $type = '';

    #[Url]
    public mixed $active = '1';

    #[Url]
    public mixed $search = '';

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', Vendor::class), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $trade = Trade::tryFrom(is_string($this->trade) ? $this->trade : '');
        $type = VendorType::tryFrom(is_string($this->type) ? $this->type : '');
        $search = is_string($this->search) ? addcslashes(trim($this->search), '%_\\') : '';

        $vendors = Vendor::query()
            ->when($trade, fn ($q, Trade $t) => $q->whereJsonContains('trades', $t->value))
            ->when($type, fn ($q, VendorType $t) => $q->where('type', $t))
            ->when(in_array($this->active, ['0', '1'], true), fn ($q) => $q->where('active', $this->active === '1'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('mobile', 'like', '%'.$search.'%')))
            // Plan ruling 15: only work orders in buildings this user sees; cost = their recorded expenses' totals.
            ->withCount(['workOrders' => fn ($q) => $q->visibleTo($this->actor())])
            ->addSelect(['cost' => Expense::query()->selectRaw('COALESCE(SUM(expenses.total), 0)')
                ->join('work_orders', 'work_orders.expense_id', '=', 'expenses.id')
                ->whereColumn('work_orders.vendor_id', 'vendors.id')
                ->where('expenses.status', ExpenseStatus::Recorded)
                ->whereIn('expenses.building_id', Building::query()->visibleTo($this->actor())->select('buildings.id'))])
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.maintenance.vendors', ['vendors' => $vendors, 'trades' => Trade::cases(), 'types' => VendorType::cases()]);
    }
}
