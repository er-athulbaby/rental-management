<?php

namespace App\Livewire\Admin\Roles;

use App\Actions\Roles\SyncRolePermissions;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Concerns\WithActor;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class Edit extends Component
{
    use WithActor;

    #[Locked]
    public int $roleId;

    /** @var array<int, string> */
    public array $permissions = [];

    public function mount(Role $role): void
    {
        $this->roleId = (int) $role->getKey();
        $this->permissions = $role->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all();
    }

    public function role(): Role
    {
        return Role::findOrFail($this->roleId);
    }

    public function readOnly(): bool
    {
        $role = $this->role();

        return $role->name === RoleName::VendorSupport->value || $this->actor()->hasRole($role->name);
    }

    public function save(SyncRolePermissions $sync): void
    {
        try {
            $sync->handle($this->actor(), $this->role(), array_values($this->permissions));
        } catch (AuthorizationException $e) {
            $this->addError('permissions', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function render(): View
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
