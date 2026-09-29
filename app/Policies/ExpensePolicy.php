<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\Expense;
use App\Models\User;

/** Spec §8.1: Finance records and reverses; Property Mgr records; finance.view holders (Admin, Management) view. */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ExpensesManage) || $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user) && self::inScope($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::ExpensesManage);
    }

    /** Attaching documents. */
    public function update(User $user, Expense $expense): bool
    {
        return $user->can(PermissionName::ExpensesManage) && self::inScope($user, $expense);
    }

    public function reverse(User $user, Expense $expense): bool
    {
        return $user->can(PermissionName::ExpensesManage) && $user->can(PermissionName::FinanceView) && self::inScope($user, $expense);
    }

    private static function inScope(User $user, Expense $expense): bool
    {
        return Building::visibleTo($user)->whereKey($expense->building_id)->exists();
    }
}
