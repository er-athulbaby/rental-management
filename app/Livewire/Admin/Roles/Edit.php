<?php

namespace App\Livewire\Admin\Roles;

use App\Actions\Roles\SyncRolePermissions;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class Edit extends Component
{
    #[Locked]
    public int $roleId;

    /** @var list<string> */
    public array $permissions = [];

    public function mount(Role $role): void
    {
        $this->roleId = $role->id;
        $this->permissions = $role->permissions()->pluck('name')->all();
    }

    public function role(): Role
    {
        return Role::findOrFail($this->roleId);
    }

    public function readOnly(): bool
    {
        $role = $this->role();

        return $role->name === RoleName::VendorSupport->value || auth()->user()->hasRole($role->name);
    }

    public function save(SyncRolePermissions $sync): void
    {
        try {
            $sync->handle(auth()->user(), $this->role(), $this->permissions);
        } catch (AuthorizationException $e) {
            $this->addError('permissions', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function render()
    {
        $groups = collect(PermissionName::cases())
            ->groupBy(fn (PermissionName $p) => str($p->value)->before('.')->headline()->toString());

        return view('livewire.admin.roles.edit', [
            'label' => RoleName::from($this->role()->name)->label(),
            'groups' => $groups,
            'readOnly' => $this->readOnly(),
        ])->title(__('Edit role'));
    }
}
