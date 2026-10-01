<?php

namespace App\Actions\Payments;

use App\Billing\AllocationTax;
use App\Billing\LargestRemainder;
use App\Enums\DepositMovementType;
use App\Enums\InvoiceChargeType;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of positive payment_allocations (spec §7.2). The caller has locked the customer row;
 * this locks the invoice lines it touches, then their invoices (spec §7.2 locking order).
 */
final class ApplyToInvoices
{
    /** @param  list<array{invoice_id: int, amount: int}>  $plan */
    public function handle(Payment $payment, array $plan, User $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyToInvoices must run inside the caller\'s transaction.');
        }

        $wanted = array_sum(array_column($plan, 'amount'));
        if ($wanted > $payment->unallocatedFils()) {
            throw ValidationException::withMessages(['allocations' => __('You can allocate at most :c BHD from this payment.', ['c' => Fils::toDecimal($payment->unallocatedFils())])]);
        }

        $now = now();
        $done = 0;

        foreach ($plan as $entry) {
            $lines = InvoiceLine::query()->where('invoice_id', $entry['invoice_id'])->orderBy('id')->lockForUpdate()->get();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($entry['invoice_id']);

            if ($invoice->customer_id !== $payment->customer_id) {
                throw new LogicException("Invoice {$invoice->id} belongs to another customer.");
            }

            $balances = $lines->mapWithKeys(fn (InvoiceLine $l) => [$l->id => $l->balanceFils()])->filter(fn (int $b) => $b > 0)->all();
            if ($entry['amount'] > array_sum($balances)) {
                throw ValidationException::withMessages(['allocations' => __('Invoice :n has only :b BHD left to pay.', ['n' => $invoice->label(), 'b' => Fils::toDecimal(array_sum($balances))])]);
            }

            $shares = LargestRemainder::split($entry['amount'], $balances);
            $invoiceSum = 0;

            foreach ($lines->whereIn('id', array_keys(array_filter($shares))) as $line) {
                $share = $shares[$line->id];
                $before = Fils::fromDecimal((string) $line->allocated);
                $tax = AllocationTax::between($before, $before + $share, Fils::fromDecimal($line->tax_amount), Fils::fromDecimal($line->total));

                $allocation = PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_line_id' => $line->id,
                    'amount' => Fils::toDecimal($share),
                    'tax_amount' => Fils::toDecimal($tax),
                    'owner_contract_id' => $line->owner_contract_id,
                    'posted_at' => $now,
                    'created_by' => $actor->id,
                ]);

                $line->forceFill(['allocated' => Fils::toDecimal($before + $share)])->save();

                // Spec §7.6: every allocation to a deposit line moves the deposit held, same sign.
                if ($line->charge_type === InvoiceChargeType::Deposit && $line->agreement_unit_id !== null) {
                    DepositMovement::create([
                        'agreement_unit_id' => $line->agreement_unit_id,
                        'owner_contract_id' => $line->owner_contract_id,
                        'type' => DepositMovementType::Received,
                        'amount' => Fils::toDecimal($share),
                        'source_type' => 'payment_allocation',
                        'source_id' => $allocation->id,
                        'posted_at' => $now,
                    ]);
                }

                $invoiceSum += $share;
            }

            $invoice->forceFill(['allocated' => Fils::toDecimal(Fils::fromDecimal((string) $invoice->allocated) + $invoiceSum)])->save();
            $done += $invoiceSum;
        }

        return $done;
    }
}
