<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\Unit;
use App\Models\User;

/** Spec §8.1 agreements.view / agreements.manage within the building scope of §8.2. */
class AgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::AgreementsView);
    }

    public function view(User $user, Agreement $agreement): bool
    {
        return $user->can(PermissionName::AgreementsView) && Agreement::visibleTo($user)->whereKey($agreement->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::AgreementsManage);
    }

    /** Writes need every unit of the agreement in scope (spec §8.2). */
    public function update(User $user, Agreement $agreement): bool
    {
        return $user->can(PermissionName::AgreementsManage) && self::allUnitsInScope($user, $agreement);
    }

    public static function allUnitsInScope(User $user, Agreement $agreement): bool
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return true;
        }

        $unitIds = $agreement->agreementUnits()->pluck('unit_id')->unique()->values()->all();

        if ($unitIds === []) {
            return $agreement->created_by === $user->getKey();
        }

        return Unit::query()->visibleTo($user)->whereKey($unitIds)->count() === count($unitIds);
    }
}
