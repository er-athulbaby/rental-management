<?php

namespace App\Livewire\Admin\Roles;

use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Title('Roles')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.roles.index', [
            'roles' => Role::query()->withCount(['permissions', 'users'])->orderBy('name')->get(),
        ]);
    }
}
