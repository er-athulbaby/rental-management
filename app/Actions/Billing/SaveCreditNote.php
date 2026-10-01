<?php

namespace App\Actions\Billing;

use App\Billing\CreditNoteSplit;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §6.5: a draft credit note against one issued invoice; amounts are gross per credited line. */
final class SaveCreditNote
{
    /** @param  array{reason?: string, lines?: array<int, array{credited_line_id: int|string, amount: string|null}>}  $data */
    public function handle(User $actor, Invoice $target, ?Invoice $draft, array $data): Invoice
    {
        if (! ($draft ? $actor->can('update', $draft) : $actor->can('credit', $target))) {
            throw new AuthorizationException;
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => __('Give the reason for the credit note.')]);
        }

        return DB::transaction(function () use ($actor, $target, $draft, $data) {
            $target = Invoice::query()->lockForUpdate()->with('lines')->findOrFail($target->id);
            $cn = $draft ? Invoice::query()->lockForUpdate()->findOrFail($draft->id) : new Invoice;

            if ($draft && ($cn->status !== InvoiceStatus::Draft || $cn->related_invoice_id !== $target->id)) {
                throw new AuthorizationException;
            }

            $entries = [];
            foreach ((array) ($data['lines'] ?? []) as $i => $entry) {
                if (blank($entry['amount'] ?? null) || preg_match('/^0+(\.0+)?$/', (string) $entry['amount'])) {
                    continue;
                }
                if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', (string) $entry['amount'])) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('Enter an amount with at most 3 decimals.')]);
                }
                /** @var InvoiceLine|null $line */
                $line = $target->lines->firstWhere('id', (int) $entry['credited_line_id']);
                $amount = Fils::fromDecimal((string) $entry['amount']);

                if ($line === null) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('Credit only lines of :n.', ['n' => $target->label()])]);
                }
                $left = Fils::fromDecimal($line->total) - Fils::fromDecimal((string) $line->credited);
                if ($amount > $left) {
                    throw ValidationException::withMessages(["lines.$i.amount" => __('At most :left BHD is left to credit on this line.', ['left' => Fils::toDecimal($left)])]);
                }
                $entries[] = [$line, $amount];
            }

            if ($entries === []) {
                throw ValidationException::withMessages(['lines' => __('Enter an amount on at least one line.')]);
            }

            $cn->forceFill([
                'type' => InvoiceType::CreditNote,
                'customer_id' => $target->customer_id,
                'agreement_id' => $target->agreement_id,
                'related_invoice_id' => $target->id,
                'credit_reason' => trim((string) ($data['reason'] ?? '')),
                'issue_date' => now('Asia/Bahrain')->toDateString(),
                'due_date' => now('Asia/Bahrain')->toDateString(),
                'status' => InvoiceStatus::Draft,
                'created_by' => $cn->created_by ?? $actor->id,
            ]);

            $subtotal = 0;
            $tax = 0;
            $rows = [];
            foreach ($entries as [$line, $amount]) {
                $split = CreditNoteSplit::of($amount, $line); // recomputed and checked again on approval
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
                    'owner_contract_id' => $line->owner_contract_id, // M4's owner ledger sees the credit on the right contract
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
        }, attempts: 3);
    }
}
