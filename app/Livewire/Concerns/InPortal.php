<?php

namespace App\Livewire\Concerns;

use App\Models\PortalAccount;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 2026-10-18 tenant portal §6: every portal query starts from the signed-in account. Protected, so the browser
 * cannot call these; they check the guard themselves because Livewire tests run no middleware (plan ruling 2).
 */
trait InPortal
{
    protected function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    protected function customerId(): int
    {
        return $this->account()->customer_id;
    }
}
