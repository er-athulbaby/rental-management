<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Invoices')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $status = '';

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

    public function render(): View
    {
        $invoices = Invoice::query()
            ->visibleTo($this->actor())
            ->with(['customer:id,name_en', 'agreement:id,number'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::cases(),
            'types' => InvoiceType::cases(),
            'canCreate' => $canCreate = $this->actor()->can('create', Invoice::class),
            'customerResults' => $canCreate ? Customer::findFor($this->actor(), $this->customerSearch) : collect(),
        ]);
    }
}
