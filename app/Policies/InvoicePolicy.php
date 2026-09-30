<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\PermissionName;
use App\Models\Invoice;
use App\Models\User;

/** Spec §8.1: Admin and Management view; Finance manages. */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FinanceView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::FinanceView) && Invoice::visibleTo($user)->whereKey($invoice->getKey())->exists();
    }

    /** "Issue now" (spec §6.3). */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesManage) && $invoice->status === InvoiceStatus::Scheduled && $this->view($user, $invoice);
    }
}
