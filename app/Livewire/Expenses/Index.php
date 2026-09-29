<?php

namespace App\Livewire\Expenses;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Expense;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Expenses')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public ?int $buildingId = null;

    #[Url]
    public string $status = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $expenses = Expense::query()
            ->visibleTo($this->actor())
            ->with(['building:id,code', 'unit:id,code', 'ownerContract:id,number'])
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('expense_date')->latest('id')
            ->paginate(25);

        return view('livewire.expenses.index', [
            'expenses' => $expenses,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
