<?php

namespace App\Billing;

use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PaymentStatus;
use App\Models\Disbursement;
use App\Models\Payment;
use App\Support\Fils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec §7.2: customer credit = Σ confirmed payment amounts − Σ their live allocations − Σ non-reversed credit refunds.
 */
final class CustomerCredit
{
    public static function fils(int $customerId): int
    {
        return self::filsFor([$customerId])[$customerId];
    }

    /**
     * The same three sums as fils(), grouped by customer: one query each for any number of customers.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, int> customer id => credit in fils, 0 for a customer with none
     */
    public static function filsFor(array $customerIds): array
    {
        $paid = Payment::query()->whereIn('customer_id', $customerIds)->where('status', PaymentStatus::Confirmed)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount) AS total')->pluck('total', 'customer_id');
        $allocated = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('p.customer_id', $customerIds)->where('p.status', PaymentStatus::Confirmed->value)
            ->groupBy('p.customer_id')->selectRaw('p.customer_id, SUM(pa.amount) AS total')->pluck('total', 'customer_id');
        $refunded = DB::table('disbursements as d')->join('payments as p', 'p.id', '=', 'd.source_id')
            ->where('d.source_type', Disbursement::SOURCE_PAYMENT)->where('d.purpose', DisbursementPurpose::CreditRefund->value)
            ->where('d.status', DisbursementStatus::Paid->value)
            ->whereIn('p.customer_id', $customerIds)->where('p.status', PaymentStatus::Confirmed->value)
            ->groupBy('p.customer_id')->selectRaw('p.customer_id, SUM(d.amount) AS total')->pluck('total', 'customer_id');

        $credit = [];
        foreach ($customerIds as $id) {
            $credit[$id] = Fils::fromDecimal((string) ($paid[$id] ?? '0'))
                - Fils::fromDecimal((string) ($allocated[$id] ?? '0'))
                - Fils::fromDecimal((string) ($refunded[$id] ?? '0'));
        }

        return $credit;
    }

    /** @return Collection<int, Payment> confirmed payments that still hold credit, oldest first */
    public static function openPayments(int $customerId): Collection
    {
        return Payment::query()
            ->where('customer_id', $customerId)
            ->where('status', PaymentStatus::Confirmed)
            ->orderBy('received_on')->orderBy('id')
            ->get()
            ->filter(fn (Payment $p) => $p->unallocatedFils() > 0)
            ->values();
    }
}
