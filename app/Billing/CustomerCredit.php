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
        $paid = (string) (Payment::query()->where('customer_id', $customerId)->where('status', PaymentStatus::Confirmed)->sum('amount') ?: '0');
        $allocated = (string) (DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('p.customer_id', $customerId)->where('p.status', PaymentStatus::Confirmed->value)->sum('pa.amount') ?: '0');

        $refunded = (string) (DB::table('disbursements as d')->join('payments as p', 'p.id', '=', 'd.source_id')
            ->where('d.source_type', Disbursement::SOURCE_PAYMENT)->where('d.purpose', DisbursementPurpose::CreditRefund->value)
            ->where('d.status', DisbursementStatus::Paid->value)
            ->where('p.customer_id', $customerId)->where('p.status', PaymentStatus::Confirmed->value)->sum('d.amount') ?: '0');

        return Fils::fromDecimal($paid) - Fils::fromDecimal($allocated) - Fils::fromDecimal($refunded);
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
