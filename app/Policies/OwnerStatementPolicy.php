<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;

class OwnerStatementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, OwnerStatement $statement): bool
    {
        return $user->can(PermissionName::FinanceView)
            && OwnerContract::visibleTo($user)->whereKey($statement->owner_contract_id)->exists();
    }

    /** Spec §8.1: disbursements.manage covers owner statements. */
    public function submit(User $user, OwnerStatement $statement): bool
    {
        return $user->can(PermissionName::DisbursementsManage) && $this->view($user, $statement);
    }
}
