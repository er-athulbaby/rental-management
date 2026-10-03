<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Invoices')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    public string $newCustomerId = '';

    public function updating(string $property): void
    {
        if ($property !== 'newCustomerId') {
            $this->resetPage();
        }
    }

    /** A manual invoice belongs to one customer: pick them, then fill in the invoice. */
    public function startInvoice(): void
    {
        abort_unless($this->actor()->can('create', Invoice::class), 403);
        $this->validate(['newCustomerId' => ['required', 'integer', Rule::in(Customer::query()->visibleTo($this->actor())->pluck('id')->all())]],
            ['newCustomerId.required' => __('Choose the customer.')]);

        $this->redirectRoute('invoices.create', ['customer' => (int) $this->newCustomerId], navigate: true);
    }

    public function render(): View
    {
        $invoices = Invoice::query()
            ->visibleTo($this->actor())
            ->with(['customer:id,name_en', 'agreement:id,number'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => InvoiceStatus::cases(),
            'types' => InvoiceType::cases(),
            'canCreate' => $canCreate = $this->actor()->can('create', Invoice::class),
            'customers' => $canCreate ? Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en']) : collect(),
        ]);
    }
}
