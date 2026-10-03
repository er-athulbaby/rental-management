<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Payments')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public string $newCustomerId = '';

    public function updating(string $property): void
    {
        if ($property !== 'newCustomerId') {
            $this->resetPage();
        }
    }

    /** A payment is received from one customer: pick them, then record it against their invoices. */
    public function startPayment(): void
    {
        abort_unless($this->actor()->can('create', Payment::class), 403);
        $this->validate(['newCustomerId' => ['required', 'integer', Rule::in(Customer::query()->visibleTo($this->actor())->pluck('id')->all())]],
            ['newCustomerId.required' => __('Choose the customer.')]);

        $this->redirectRoute('payments.create', ['customer' => (int) $this->newCustomerId], navigate: true);
    }

    public function render(): View
    {
        $payments = Payment::query()
            ->whereIn('customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->with('customer:id,name_en')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->latest('received_on')->latest('id')
            ->paginate(50);

        return view('livewire.payments.index', [
            'payments' => $payments,
            'canCreate' => $canCreate = $this->actor()->can('create', Payment::class),
            'customers' => $canCreate ? Customer::query()->visibleTo($this->actor())->orderBy('name_en')->get(['id', 'name_en']) : collect(),
        ]);
    }
}
