<?php

namespace App\Livewire\Buildings;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Buildings')]
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
        $buildings = Building::query()
            ->visibleTo($this->actor())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('code', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.buildings.index', ['buildings' => $buildings]);
    }
}
