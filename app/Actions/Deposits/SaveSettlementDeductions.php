<?php

namespace App\Actions\Deposits;

use App\Enums\DeductionType;
use App\Enums\DepositSettlementStatus;
use App\Enums\InvoiceStatus;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §7.7: Finance completes the deductions, each with a reason (the description). Replaces the draft's lines. */
final class SaveSettlementDeductions
{
    /** @param  list<array<string, mixed>>  $lines */
    public function handle(User $actor, DepositSettlement $draft, array $lines): DepositSettlement
    {
        if (! $actor->can('update', $draft)) {
            throw new AuthorizationException;
        }

        $v = Validator::make(['lines' => $lines], [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.agreement_unit_id' => ['required', 'integer'],
            'lines.*.type' => ['required', Rule::enum(DeductionType::class)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.invoice_line_id' => ['required_if:lines.*.type,unpaid_rent', 'nullable', 'integer'],
        ])->validate()['lines'];

        return DB::transaction(function () use ($draft, $v) {
            $settlement = DepositSettlement::query()->lockForUpdate()->with('units')->findOrFail($draft->id);
            if ($settlement->status !== DepositSettlementStatus::Draft) {
                throw ValidationException::withMessages(['lines' => __('Only a draft settlement can be edited.')]);
            }
            $unitIds = $settlement->units->pluck('agreement_unit_id')->all();

            foreach ($v as $i => $line) {
                if (! in_array((int) $line['agreement_unit_id'], $unitIds, true)) {
                    throw ValidationException::withMessages(["lines.$i.agreement_unit_id" => __('Choose a unit of this settlement.')]);
                }
                if ($line['type'] === DeductionType::UnpaidRent->value) {
                    $invoiceLine = InvoiceLine::query()->with('invoice')->find((int) $line['invoice_line_id']);
                    if ($invoiceLine === null || $invoiceLine->agreement_unit_id !== (int) $line['agreement_unit_id']
                        || $invoiceLine->invoice?->status !== InvoiceStatus::Issued) {
                        throw ValidationException::withMessages(["lines.$i.invoice_line_id" => __('Choose an issued invoice line of this unit.')]);
                    }
                    if (Fils::fromDecimal((string) $line['amount']) > $invoiceLine->balanceFils()) {
                        throw ValidationException::withMessages(["lines.$i.amount" => __('At most :b BHD is unpaid on that line.', ['b' => Fils::toDecimal($invoiceLine->balanceFils())])]);
                    }
                }
            }

            $settlement->lines()->delete(); // draft: the trigger allows it
            foreach ($v as $line) {
                $settlement->lines()->create([
                    'agreement_unit_id' => (int) $line['agreement_unit_id'],
                    'type' => $line['type'],
                    'description' => $line['description'],
                    'amount' => Fils::toDecimal(Fils::fromDecimal((string) $line['amount'])),
                    'invoice_line_id' => $line['type'] === DeductionType::UnpaidRent->value ? (int) $line['invoice_line_id'] : null,
                ]);
            }
            // Re-read the held amounts too: payments may have landed since the draft was made.
            foreach ($settlement->units as $unit) {
                $unit->forceFill(['held_amount' => Fils::toDecimal(DepositMovement::heldFils($unit->agreement_unit_id))])->save();
            }

            return $settlement->load(['units', 'lines']);
        }, attempts: 3);
    }
}
