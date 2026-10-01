<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spec §6.2: one line per agreement unit with a deposit, out of scope, due on the start date, issued at activation.
 * Amendments bill one new unit; renewals bill only what the transfer did not cover (spec §5.8).
 */
final class CreateDepositInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    /** @param  array<int, int>|null  $amounts  agreement unit id => fils; null = each unit's full deposit */
    public function handle(Agreement $agreement, User $actor, ?array $amounts = null): ?Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreateDepositInvoice must run inside the caller\'s transaction.');
        }

        $agreement->load('agreementUnits.unit.building');
        $lines = $agreement->agreementUnits
            ->map(fn ($au) => [$au, $amounts === null ? Fils::fromDecimal($au->deposit_amount) : ($amounts[$au->id] ?? 0)])
            ->filter(fn (array $pair) => $pair[1] > 0)
            ->map(fn (array $pair) => [
                'agreement_unit_id' => $pair[0]->id,
                'unit_id' => $pair[0]->unit_id,
                'charge_type' => InvoiceChargeType::Deposit->value,
                'description' => __('Security deposit — :b / :u', ['b' => $pair[0]->unit->building->code, 'u' => $pair[0]->unit->code]),
                'net' => Fils::toDecimal($pair[1]),
                'tax_category' => TaxCategory::OutOfScope->value,
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => Fils::toDecimal($pair[1]),
            ])->values()->all();

        if ($lines === []) {
            return null;
        }

        $sum = Fils::toDecimal(array_sum(array_map(fn (array $l) => Fils::fromDecimal($l['net']), $lines)));

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Deposit,
            'customer_id' => $agreement->customer_id,
            'agreement_id' => $agreement->id,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => $agreement->agreementUnits->whereIn('id', array_column($lines, 'agreement_unit_id'))->min('start_date')->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $sum,
            'tax_total' => '0.000',
            'total' => $sum,
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->createMany($lines);

        // Scheduled before issuing so a hold-back (§4.6) is retried by IssueDueInvoices (issue_date is today).
        $invoice->forceFill(['status' => InvoiceStatus::Scheduled])->save();
        $this->issue->handle($invoice, $actor);

        return $invoice->refresh();
    }
}
