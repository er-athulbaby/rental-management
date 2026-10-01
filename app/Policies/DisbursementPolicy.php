<?php

namespace App\Policies;

use App\Enums\PayeeType;
use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;

/** Spec §8.1: Finance records payments out; finance.view holders see them. */
class DisbursementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Disbursement $disbursement): bool
    {
        if (! $user->can(PermissionName::FinanceView)) {
            return false;
        }

        return $disbursement->payee_type !== PayeeType::Customer
            || Customer::visibleTo($user)->whereKey($disbursement->payee_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::DisbursementsManage);
    }

    public function reverse(User $user, Disbursement $disbursement): bool
    {
        return $user->can(PermissionName::DisbursementsManage) && $this->view($user, $disbursement);
    }
}
