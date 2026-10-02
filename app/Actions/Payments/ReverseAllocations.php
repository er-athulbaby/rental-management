<?php

namespace App\Actions\Payments;

use App\Billing\LineTax;
use App\Enums\DepositMovementType;
use App\Enums\InvoiceChargeType;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of negative payment_allocations (spec §6.5, §7.3). A row may take back part of an allocation;
 * Σ reversals never exceed it. The caller has locked the customer; this locks lines (ascending), then invoices.
 */
final class ReverseAllocations
{
    /** @param  list<array{allocation: PaymentAllocation, amount: int}>  $cuts */
    public function handle(array $cuts, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReverseAllocations must run inside the caller\'s transaction.');
        }

        $cuts = array_values(array_filter($cuts, fn (array $c) => $c['amount'] > 0));
        if ($cuts === []) {
            return;
        }

        $lineIds = collect($cuts)->map(fn (array $c) => $c['allocation']->invoice_line_id)->unique()->sort()->values();
        $lines = InvoiceLine::query()->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $invoices = Invoice::query()->whereIn('id', $lines->pluck('invoice_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $now = now();
        $perInvoice = [];

        foreach ($cuts as $cut) {
            $allocation = PaymentAllocation::query()->findOrFail($cut['allocation']->id); // fresh: live amount after earlier cuts
            if ($cut['amount'] > $allocation->liveFils()) {
                throw new LogicException("Cannot reverse more than is live on allocation {$allocation->id}.");
            }

            $line = $lines[$allocation->invoice_line_id] ?? throw new LogicException("Line {$allocation->invoice_line_id} was not locked.");
            $before = Fils::fromDecimal((string) $line->allocated);
            $after = $before - $cut['amount'];
            $tax = LineTax::allocationShare($line, $after, -$cut['amount']);

            $row = PaymentAllocation::create([
                'payment_id' => $allocation->payment_id,
                'invoice_line_id' => $line->id,
                'amount' => Fils::toDecimal(-$cut['amount']),
                'tax_amount' => Fils::toDecimal($tax),
                'reverses_allocation_id' => $allocation->id,
                'owner_contract_id' => $allocation->owner_contract_id,
                'posted_at' => $now,
                'created_by' => $actor->id,
            ]);

            $line->forceFill(['allocated' => Fils::toDecimal($after)])->save();

            if ($line->charge_type === InvoiceChargeType::Deposit && $line->agreement_unit_id !== null) {
                DepositMovement::create([
                    'agreement_unit_id' => $line->agreement_unit_id,
                    'owner_contract_id' => $line->owner_contract_id,
                    'type' => DepositMovementType::Received,
                    'amount' => Fils::toDecimal(-$cut['amount']),
                    'source_type' => 'payment_allocation',
                    'source_id' => $row->id,
                    'posted_at' => $now,
                ]);
            }

            $perInvoice[$line->invoice_id] = ($perInvoice[$line->invoice_id] ?? 0) + $cut['amount'];
        }

        foreach ($perInvoice as $invoiceId => $fils) {
            $invoice = $invoices[$invoiceId] ?? throw new LogicException("Invoice {$invoiceId} was not locked.");
            $invoice->forceFill(['allocated' => Fils::toDecimal(Fils::fromDecimal((string) $invoice->allocated) - $fils)])->save();
        }
    }
}
