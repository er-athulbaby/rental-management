<?php

namespace App\Actions\Billing;

use App\Billing\CreditNoteSplit;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Spec §6.5: writes a draft credit note against one issued invoice. Internal: callers validated the entries. */
final class BuildCreditNote
{
    /** @param  list<array{0: InvoiceLine, 1: int}>  $entries  credited line and gross fils */
    public function handle(Invoice $target, array $entries, string $reason, User $actor, ?Invoice $draft = null): Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('BuildCreditNote must run inside the caller\'s transaction.');
        }

        $cn = $draft ?? new Invoice;
        $cn->forceFill([
            'type' => InvoiceType::CreditNote,
            'customer_id' => $target->customer_id,
            'agreement_id' => $target->agreement_id,
            'related_invoice_id' => $target->id,
            'credit_reason' => $reason,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => now('Asia/Bahrain')->toDateString(),
            'status' => InvoiceStatus::Draft,
            'created_by' => $cn->created_by ?? $actor->id,
        ]);

        $subtotal = 0;
        $tax = 0;
        $rows = [];
        foreach ($entries as [$line, $amount]) {
            $split = CreditNoteSplit::of($amount, $line);
            $rows[] = [
                'credited_line_id' => $line->id,
                'agreement_unit_id' => $line->agreement_unit_id,
                'unit_id' => $line->unit_id,
                'charge_type' => $line->charge_type,
                'description' => __('Credit: :d', ['d' => $line->description]),
                'period_start' => $line->period_start,
                'period_end' => $line->period_end,
                'net' => Fils::toDecimal($split['net']),
                'tax_category' => $line->tax_category,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => Fils::toDecimal($split['tax']),
                'total' => Fils::toDecimal($amount),
                'owner_contract_id' => $line->owner_contract_id,
            ];
            $subtotal += $split['net'];
            $tax += $split['tax'];
        }

        $cn->forceFill(['subtotal' => Fils::toDecimal($subtotal), 'tax_total' => Fils::toDecimal($tax), 'total' => Fils::toDecimal($subtotal + $tax)])->save();
        $cn->lines()->delete();
        foreach ($rows as $row) {
            $cn->lines()->create($row);
        }

        return $cn->refresh()->load('lines');
    }
}
