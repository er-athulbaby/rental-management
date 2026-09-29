<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SyncUserBuildings
{
    /** @param  list<int>  $buildingIds */
    public function handle(User $actor, User $user, array $buildingIds): void
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user, $buildingIds) {
            $old = $user->buildings()->orderBy('buildings.id')->pluck('buildings.id')->all();
            $new = collect($buildingIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($old === $new) {
                return;
            }

            $user->buildings()->sync($new);

            Audit::log('user.buildings.changed', $user, ['buildings' => $old], ['buildings' => $new], causer: $actor);
        });
    }
}
