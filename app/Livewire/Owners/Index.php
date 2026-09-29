<?php

namespace App\Livewire\Owners;

use App\Models\Owner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Owners')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $owners = Owner::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$this->search.'%')
                ->orWhere('id_number', $this->search)))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.owners.index', ['owners' => $owners]);
    }
}
