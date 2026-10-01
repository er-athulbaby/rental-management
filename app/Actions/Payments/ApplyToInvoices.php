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
use Carbon\CarbonInterface;
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
                $this->writeLine($payment, $line, $shares[$line->id], $actor, $now);
                $invoiceSum += $shares[$line->id];
            }

            $invoice->forceFill(['allocated' => Fils::toDecimal(Fils::fromDecimal((string) $invoice->allocated) + $invoiceSum)])->save();
            $done += $invoiceSum;
        }

        return $done;
    }

    /**
     * Spec §7.2, §7.7: allocate named amounts to named lines (deposit settlements). Locks the lines ascending, then their
     * invoices; the caller holds the customer lock.
     *
     * @param  array<int, int>  $lineAmounts  invoice line id => fils
     */
    public function toLines(Payment $payment, array $lineAmounts, User $actor): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyToInvoices must run inside the caller\'s transaction.');
        }

        $lineAmounts = array_filter($lineAmounts, fn (int $f) => $f > 0);
        if (array_sum($lineAmounts) > $payment->unallocatedFils()) {
            throw new LogicException('Line allocations exceed the payment.');
        }

        $lines = InvoiceLine::query()->whereKey(array_keys($lineAmounts))->orderBy('id')->lockForUpdate()->get();
        $invoices = Invoice::query()->whereKey($lines->pluck('invoice_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $now = now();
        $perInvoice = [];

        foreach ($lines as $line) {
            $share = $lineAmounts[$line->id];
            $invoice = $invoices->get($line->invoice_id) ?? throw new LogicException("Invoice {$line->invoice_id} was not locked.");
            if ($share > $line->balanceFils() || $invoice->customer_id !== $payment->customer_id) {
                throw new LogicException("Cannot allocate {$share} fils to line {$line->id}.");
            }
            $this->writeLine($payment, $line, $share, $actor, $now);
            $perInvoice[$line->invoice_id] = ($perInvoice[$line->invoice_id] ?? 0) + $share;
        }

        foreach ($perInvoice as $invoiceId => $fils) {
            $invoice = $invoices->get($invoiceId) ?? throw new LogicException("Invoice {$invoiceId} was not locked.");
            $invoice->forceFill(['allocated' => Fils::toDecimal(Fils::fromDecimal((string) $invoice->allocated) + $fils)])->save();
        }

        return array_sum($perInvoice);
    }

    /** One positive allocation row, the line's cache, and the deposit movement for a deposit line (spec §7.2, §7.6). */
    private function writeLine(Payment $payment, InvoiceLine $line, int $share, User $actor, CarbonInterface $now): void
    {
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
    }
}
