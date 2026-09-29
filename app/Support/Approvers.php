<?php

namespace App\Support;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

final class Approvers
{
    /** Active approvals.decide holders, never Vendor Support (spec §8.1: no business emails), minus $except. @return Collection<int, User> */
    public static function notifiable(User ...$except): Collection
    {
        $excluded = array_map(fn (User $user) => $user->getKey(), $except);

        return User::query()
            ->where('active', true)
            ->permission(PermissionName::ApprovalsDecide->value)
            ->whereKeyNot($excluded)
            ->get()
            ->reject(fn (User $user) => $user->hasRole(RoleName::VendorSupport))
            ->values();
    }
}
