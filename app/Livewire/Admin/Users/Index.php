<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active'; // active | inactive | all

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $users = User::query()
            ->with('roles')
            ->withCount('buildings')
            ->where('is_system', false)
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->status !== 'all', fn ($q) => $q->where('active', $this->status === 'active'))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.users.index', ['users' => $users]);
    }
}
