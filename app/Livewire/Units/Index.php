<?php

namespace App\Livewire\Units;

use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Units')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $buildingId = null;

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $units = Unit::query()
            ->visibleTo($this->actor())
            ->withOccupancy()
            ->with('building:id,code,name')
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->orderBy('building_id')->orderBy('code')
            ->paginate(50);

        return view('livewire.units.index', [
            'units' => $units,
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
