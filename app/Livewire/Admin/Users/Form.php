<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetUserTwoFactor;
use App\Actions\Users\SyncUserBuildings;
use App\Actions\Users\SyncUserRoles;
use App\Actions\Users\UpdateUserProfile;
use App\Enums\RoleName;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use WithActor;

    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    /** @var array<int, string> */
    public array $roles = [];

    /** @var array<int, int> */
    public array $buildingIds = [];

    public function mount(?User $user = null): void
    {
        abort_if($user?->is_system === true, 404);

        if ($user?->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roles = $user->getRoleNames()->map(fn (mixed $name): string => (string) $name)->values()->all();
            $this->buildingIds = $user->buildings()->pluck('buildings.id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        }
    }

    /** The user being edited; only valid in edit mode. */
    private function subject(): User
    {
        return User::findOrFail($this->userId);
    }

    public function user(): ?User
    {
        return $this->userId ? User::findOrFail($this->userId) : null;
    }

    public function save(CreateUser $create, UpdateUserProfile $profile, SyncUserRoles $roles, SyncUserBuildings $buildings): void
    {
        $actor = $this->actor();

        try {
            if (! $user = $this->user()) {
                $create->handle($actor, $this->name, $this->email, array_values($this->roles), array_values($this->buildingIds));
            } else {
                DB::transaction(function () use ($actor, $user, $profile, $roles, $buildings) {
                    $profile->handle($actor, $user, $this->name, $this->email);
                    if ($user->getRoleNames()->sort()->values()->all() !== collect($this->roles)->sort()->values()->all()) {
                        $roles->handle($actor, $user, array_values($this->roles));
                    }
                    $buildings->handle($actor, $user, array_values($this->buildingIds));
                });
            }
        } catch (AuthorizationException $e) {
            $this->addError('roles', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('User saved.'));
        $this->redirectRoute('admin.users.index', navigate: true);
    }

    public function deactivate(DeactivateUser $action): void
    {
        $this->run(fn () => $action->handle($this->actor(), $this->subject()), __('User deactivated.'));
    }

    public function reactivate(ReactivateUser $action): void
    {
        $this->run(fn () => $action->handle($this->actor(), $this->subject()), __('User reactivated.'));
    }

    public function resetTwoFactor(ResetUserTwoFactor $action): void
    {
        $this->run(fn () => $action->handle($this->actor(), $this->subject()), __('Two-factor authentication reset.'));
    }

    private function run(callable $action, string $message): void
    {
        try {
            $action();
            Flux::toast(variant: 'success', text: $message);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage() ?: __('This action is not allowed.'));
        }
    }

    public function render(): View
    {
        return view('livewire.admin.users.form', [
            'subject' => $this->user(),
            'roleOptions' => array_filter(RoleName::cases(), fn (RoleName $role) => $role !== RoleName::VendorSupport),
            'buildings' => Building::query()->orderBy('name')->get(['id', 'name', 'code']),
        ])->title($this->userId ? __('Edit user') : __('New user'));
    }
}
