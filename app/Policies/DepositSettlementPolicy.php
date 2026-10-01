<?php

namespace App\Policies;

use App\Enums\DepositSettlementStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\DepositSettlement;
use App\Models\User;

/** Spec §7.7: Finance completes the deductions; finance.view holders see settlements. */
class DepositSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, DepositSettlement $settlement): bool
    {
        return $user->can(PermissionName::FinanceView) && Agreement::visibleTo($user)->whereKey($settlement->agreement_id)->exists();
    }

    public function update(User $user, DepositSettlement $settlement): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $settlement->status === DepositSettlementStatus::Draft && $this->view($user, $settlement);
    }
}
