<?php

namespace App\Actions\Payments;

use App\Billing\CustomerCredit;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec §7.2: whenever an invoice is issued for a customer with credit, the credit is allocated oldest-first,
 * drawing from the oldest payments. Runs in its own transaction (after the issuing one has committed).
 */
final class AllocateCustomerCredit
{
    public function __construct(private ApplyToInvoices $apply) {}

    public function handle(int $customerId, ?User $actor = null): int
    {
        return DB::transaction(function () use ($customerId, $actor) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $done = 0;

            foreach (CustomerCredit::openPayments($customer->id) as $payment) {
                $plan = AllocationPlan::oldestFirst($customer->id, $payment->unallocatedFils());
                if ($plan === []) {
                    break; // nothing left to pay
                }
                $done += $this->apply->handle($payment, $plan, $actor ?? $payment->recorder()->firstOrFail());
            }

            return $done;
        }, attempts: 3);
    }
}
