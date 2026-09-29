<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class SyncUserRoles
{
    /** @param  list<string>  $roles  role names (RoleName values) */
    public function handle(User $actor, User $user, array $roles): void
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }
        if ($actor->is($user)) {
            throw new AuthorizationException(__('You cannot change your own roles.'));
        }
        if (in_array(RoleName::VendorSupport->value, $roles, true) || $user->isVendorSupport()) {
            throw new AuthorizationException(__('The Vendor Support role is managed on the server only.'));
        }

        DB::transaction(function () use ($actor, $user, $roles) {
            $oldRoles = $user->getRoleNames()->sort()->values()->all();
            $oldPermissions = $user->getAllPermissions()->pluck('name')->all();

            $user->syncRoles($roles);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $newRoles = $user->getRoleNames()->sort()->values()->all();
            if ($oldRoles === $newRoles) {
                return;
            }

            Audit::log('user.roles.changed', $user, ['roles' => $oldRoles], ['roles' => $newRoles], causer: $actor);

            $granted = array_values(array_intersect(
                array_diff($user->getAllPermissions()->pluck('name')->all(), $oldPermissions),
                array_map(fn (PermissionName $p) => $p->value, PermissionName::sensitiveGrants()),
            ));

            if ($granted !== []) {
                Notification::send(Approvers::notifiable($actor, $user), new SensitiveAccessGranted($user, $granted, $actor));
            }
        });
    }
}
