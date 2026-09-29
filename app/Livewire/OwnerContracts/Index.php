<?php

namespace App\Livewire\OwnerContracts;

use App\Enums\OwnerContractStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\OwnerContract;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Owner contracts')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $contracts = OwnerContract::query()
            ->visibleTo($this->actor())
            ->with(['owner:id,name_en', 'building:id,code,name'])
            ->withCount('units')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('owner', fn ($o) => $o->where('name_en', 'like', '%'.$this->search.'%'))))
            ->latest('id')
            ->paginate(25);

        return view('livewire.owner-contracts.index', [
            'contracts' => $contracts,
            'statuses' => OwnerContractStatus::cases(),
        ]);
    }
}
