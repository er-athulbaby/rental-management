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
 * ponytail: M3 renewals bill only new deposit − transferred-in; imported agreements (M5) skip this.
 */
final class CreateDepositInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    public function handle(Agreement $agreement, User $actor): ?Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CreateDepositInvoice must run inside the caller\'s transaction.');
        }

        $agreement->load('agreementUnits.unit.building');
        $lines = $agreement->agreementUnits
            ->filter(fn ($au) => Fils::fromDecimal($au->deposit_amount) > 0)
            ->map(fn ($au) => [
                'agreement_unit_id' => $au->id,
                'unit_id' => $au->unit_id,
                'charge_type' => InvoiceChargeType::Deposit->value,
                'description' => __('Security deposit — :b / :u', ['b' => $au->unit->building->code, 'u' => $au->unit->code]),
                'net' => $au->deposit_amount,
                'tax_category' => TaxCategory::OutOfScope->value,
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => $au->deposit_amount,
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
            'due_date' => $agreement->start_date->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => $sum,
            'tax_total' => '0.000',
            'total' => $sum,
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $invoice->lines()->createMany($lines);

        $this->issue->handle($invoice, $actor);

        return $invoice->refresh();
    }
}
