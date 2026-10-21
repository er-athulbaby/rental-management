<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 2026-10-18 tenant portal §6 and 2026-10-21 owner portal §6: every portal query starts from the signed-in
 * account. Protected, so the browser cannot call these; they check the guard themselves because Livewire tests run no
 * middleware (tenant plan ruling 2). A side the account does not have is a 404 (owner portal plan ruling 1).
 */
trait InPortal
{
    protected function account(): PortalAccount
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount && $account->isActive(), 403);

        return $account;
    }

    /** The account's tenant: never null here, so no query ever matches `customer_id IS NULL`. */
    protected function customerId(): int
    {
        $account = $this->account();
        abort_unless($account->hasTenant(), 404);

        return (int) $account->customer_id;
    }

    protected function customer(): Customer
    {
        return Customer::query()->findOrFail($this->customerId());
    }

    protected function ownerId(): int
    {
        $account = $this->account();
        abort_unless($account->hasOwner(), 404);

        return (int) $account->owner_id;
    }

    protected function owner(): Owner
    {
        return Owner::query()->findOrFail($this->ownerId());
    }
}
