<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Customers')]
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
        $term = trim($this->search);

        $customers = Customer::query()
            ->visibleTo($this->actor())
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$term.'%')
                ->orWhere('id_number', $term)
                ->orWhere('mobile', $term)))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.customers.index', ['customers' => $customers, 'hint' => null]);
    }
}
