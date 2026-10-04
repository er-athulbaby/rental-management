<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Payments')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public string $customerSearch = '';

    public function updating(string $property): void
    {
        if ($property !== 'customerSearch') {
            $this->resetPage();
        }
    }

    /** A payment is received from one customer: pick them, then record it against their invoices. */
    public function startPayment(int $customerId): void
    {
        abort_unless($this->actor()->can('create', Payment::class), 403);
        $customer = Customer::query()->visibleTo($this->actor())->findOrFail($customerId);

        $this->redirectRoute('payments.create', ['customer' => $customer->id], navigate: true);
    }

    public function render(): View
    {
        $payments = Payment::query()
            ->whereIn('customer_id', Customer::query()->visibleTo($this->actor())->select('id'))
            ->with(['customer:id,name_en', 'tenders'])
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->latest('received_on')->latest('id')
            ->paginate(50);

        return view('livewire.payments.index', [
            'payments' => $payments,
            'canCreate' => $canCreate = $this->actor()->can('create', Payment::class),
            'customerResults' => $canCreate ? Customer::findFor($this->actor(), $this->customerSearch) : collect(),
        ]);
    }
}
