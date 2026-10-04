<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Fils;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Invoices')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    public const array TABS = ['unpaid', 'overdue', 'paid', 'upcoming', 'drafts', 'all'];

    #[Url]
    public string $tab = 'unpaid';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    public string $customerSearch = '';

    public function updating(string $property): void
    {
        if ($property !== 'customerSearch') {
            $this->resetPage();
        }
    }

    /** A manual invoice belongs to one customer: pick them, then fill in the invoice. */
    public function startInvoice(int $customerId): void
    {
        abort_unless($this->actor()->can('create', Invoice::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail($customerId);

        $this->redirectRoute('invoices.create', ['customer' => $customer->id], navigate: true);
    }

    /**
     * The other filters (type, building, search), shared by every tab and its count.
     *
     * @return Builder<Invoice>
     */
    private function filtered(): Builder
    {
        return Invoice::query()
            ->visibleTo($this->actor())
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))));
    }

    /**
     * Unpaid and overdue match the dashboard and the ageing report (Queries::openInvoices / overdueInvoices).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function inTab(Builder $query, string $tab): Builder
    {
        $owed = fn (Builder $q) => $q->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote);

        return match ($tab) {
            'unpaid' => $owed($query)->where('balance', '>', 0),
            'overdue' => $owed($query)->where('balance', '>', 0)->where('grace_until', '<', now('Asia/Bahrain')->toDateString()),
            'paid' => $owed($query)->where('balance', '<=', 0),
            'upcoming' => $query->where('status', InvoiceStatus::Scheduled),
            'drafts' => $query->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::PendingApproval]),
            default => $query,
        };
    }

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'unpaid';

        $invoices = self::inTab($this->filtered(), $tab)
            ->with(['customer:id,name_en', 'agreement:id,number'])
            ->when($tab === 'paid' || $tab === 'all', fn ($q) => $q->orderByDesc('due_date')->orderByDesc('id'), fn ($q) => $q->orderBy('due_date')->orderBy('id'))
            ->paginate(50);

        $counts = [];
        foreach (self::TABS as $t) {
            $counts[$t] = self::inTab($this->filtered(), $t)->count();
        }

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'currentTab' => $tab,
            'counts' => $counts,
            'owed' => collect(['unpaid', 'overdue'])->mapWithKeys(fn ($t) => [$t => Fils::toDecimal(Fils::fromDecimal((string) (self::inTab($this->filtered(), $t)->sum('balance') ?: '0')))])->all(),
            'types' => InvoiceType::cases(),
            'canCreate' => $canCreate = $this->actor()->can('create', Invoice::class),
            'customerResults' => $canCreate ? Customer::findFor($this->actor(), $this->customerSearch) : collect(),
        ]);
    }
}
