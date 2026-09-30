<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CustomersView);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersView) && Customer::visibleTo($user)->whereKey($customer->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CustomersManage);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(PermissionName::CustomersManage) && Customer::visibleTo($user)->whereKey($customer->getKey())->exists();
    }
}
