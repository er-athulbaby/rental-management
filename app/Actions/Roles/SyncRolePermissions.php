<?php

namespace App\Actions\Roles;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

final class SyncRolePermissions
{
    /** @param  list<string>  $permissions  permission names */
    public function handle(User $actor, Role $role, array $permissions): void
    {
        if (! $actor->can(PermissionName::RolesManage)) {
            throw new AuthorizationException;
        }
        if ($actor->hasRole($role->name)) {
            throw new AuthorizationException(__('You cannot change a role you hold.'));
        }
        if ($role->name === RoleName::VendorSupport->value) {
            throw new AuthorizationException(__('The Vendor Support role is managed on the server only.'));
        }

        $valid = array_map(fn (PermissionName $p) => $p->value, PermissionName::cases());
        $new = collect($permissions)->intersect($valid)->unique()->sort()->values()->all();

        DB::transaction(function () use ($actor, $role, $new) {
            $old = $role->permissions()->pluck('name')->sort()->values()->all();
            if ($old === $new) {
                return;
            }

            $role->syncPermissions($new); // also clears the permission cache

            Audit::log('role.permissions.changed', $role, ['permissions' => $old], ['permissions' => $new], causer: $actor);

            $granted = array_values(array_intersect(
                array_diff($new, $old),
                array_map(fn (PermissionName $p) => $p->value, PermissionName::sensitiveGrants()),
            ));

            if ($granted === []) {
                return;
            }

            foreach (User::role($role->name)->get() as $holder) {
                Notification::send(Approvers::notifiable($actor, $holder), new SensitiveAccessGranted($holder, $granted, $actor));
            }
        });
    }
}
