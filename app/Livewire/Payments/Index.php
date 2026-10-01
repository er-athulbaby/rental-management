<?php

namespace App\Livewire\Payments;

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
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
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

        return view('livewire.payments.index', ['payments' => $payments]);
    }
}
