<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;

/** Spec §8.1: Finance records; Admin and Management view. */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(PermissionName::FinanceView) && Customer::visibleTo($user)->whereKey($payment->customer_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PaymentsManage);
    }
}
