<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Cheque;
use App\Models\User;

/** Spec §8.1: Finance manages cheques; finance.view holders see them. */
class ChequePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Cheque $cheque): bool
    {
        return $user->can(PermissionName::FinanceView) && Cheque::visibleTo($user)->whereKey($cheque->id)->exists();
    }

    public function manage(User $user): bool
    {
        return $user->can(PermissionName::ChequesManage);
    }
}
