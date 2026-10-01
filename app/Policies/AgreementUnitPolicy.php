<?php

namespace App\Policies;

use App\Models\AgreementUnit;
use App\Models\User;

/** Documents on an agreement unit follow its agreement (spec §9.1). */
class AgreementUnitPolicy
{
    public function view(User $user, AgreementUnit $unit): bool
    {
        return $user->can('view', $unit->agreement);
    }

    public function update(User $user, AgreementUnit $unit): bool
    {
        return $user->can('update', $unit->agreement);
    }
}
