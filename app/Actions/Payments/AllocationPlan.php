<?php

namespace App\Actions\Payments;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Support\Fils;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Which invoices a payment goes to (spec §7.2 step 1). Read inside the caller's locked transaction. */
final class AllocationPlan
{
    /** @return list<array{invoice_id: int, amount: int}> oldest due first, then lowest id; $firstInvoiceId (a cheque's target) goes first */
    public static function oldestFirst(int $customerId, int $amountFils, ?int $firstInvoiceId = null): array
    {
        $plan = [];
        $left = $amountFils;

        $invoices = self::open($customerId)
            ->sortBy(fn (Invoice $i) => [$i->id === $firstInvoiceId ? 0 : 1, $i->due_date->toDateString(), $i->id])
            ->values();

        foreach ($invoices as $invoice) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, Fils::fromDecimal($invoice->balance));
            if ($take > 0) {
                $plan[] = ['invoice_id' => $invoice->id, 'amount' => $take];
                $left -= $take;
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{invoice_id: int|string, amount: string}>  $entries  BHD amounts as entered
     * @return list<array{invoice_id: int, amount: int}>
     */
    public static function explicit(int $customerId, array $entries): array
    {
        $open = self::open($customerId)->keyBy('id');
        $plan = [];

        foreach ($entries as $i => $entry) {
            $invoice = $open->get((int) $entry['invoice_id']);
            $amount = Fils::fromDecimal((string) $entry['amount']);

            if ($invoice === null) {
                throw ValidationException::withMessages(["allocations.$i.invoice_id" => __('Choose an open invoice of this tenant.')]);
            }
            if ($amount <= 0 || $amount > Fils::fromDecimal($invoice->balance)) {
                throw ValidationException::withMessages(["allocations.$i.amount" => __('Allocate more than zero and at most the invoice balance (:b).', ['b' => $invoice->balance])]);
            }
            $plan[] = ['invoice_id' => $invoice->id, 'amount' => $amount];
        }

        return $plan;
    }

    /** @return Collection<int, Invoice> issued, non-credit-note invoices with a balance */
    private static function open(int $customerId): Collection
    {
        return Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Issued)
            ->where('type', '!=', InvoiceType::CreditNote)
            ->where('balance', '>', 0)
            ->get();
    }
}
