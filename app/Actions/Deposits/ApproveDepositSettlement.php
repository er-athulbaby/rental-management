<?php

namespace App\Actions\Deposits;

use App\Actions\Billing\IssueInvoice;
use App\Actions\NextDocumentNumber;
use App\Actions\Payments\ApplyToInvoices;
use App\Actions\Payments\PostPayment;
use App\Enums\DeductionType;
use App\Enums\DepositMovementType;
use App\Enums\DepositSettlementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\NumberSequenceKey;
use App\Enums\PaymentMethod;
use App\Models\AgreementUnit;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §7.7 on approval, in one transaction. Locks: customer → settlement → (deductions invoice, new) → named lines →
 * invoices → payment, the global order. Credit auto-allocation is suppressed: the deductions invoice issues with
 * autoAllocate false, and the deposit_applied payment is allocated explicitly.
 */
final class ApproveDepositSettlement
{
    public function __construct(
        private IssueInvoice $issue,
        private PostPayment $post,
        private ApplyToInvoices $apply,
        private NextDocumentNumber $next,
    ) {}

    public function handle(DepositSettlement $pending, User $approver): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApproveDepositSettlement must run inside the caller\'s transaction.');
        }

        $agreement = $pending->agreement()->firstOrFail();
        $customer = Customer::query()->lockForUpdate()->findOrFail($agreement->customer_id);
        $settlement = DepositSettlement::query()->lockForUpdate()->with(['units', 'lines'])->findOrFail($pending->id);
        if ($settlement->status !== DepositSettlementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This settlement is no longer waiting for approval.')]);
        }

        // 1. Non-rent deductions → one issued manual invoice, attributed on the unit's last day (plan ruling 7).
        $invoice = $this->deductionsInvoice($settlement, $agreement->id, $customer->id, $approver);
        $deductionLines = InvoiceLine::query()->where('invoice_id', $invoice?->id)->orderBy('id')->get(); // none when there is no invoice

        // 2. Per unit: held, capped deductions, applied (re-read under the locks above).
        $plan = [];
        $heldBy = [];
        $useBy = [];
        foreach ($settlement->units as $unit) {
            $held = DepositMovement::heldFils($unit->agreement_unit_id);
            $left = $held;
            $rentLines = $settlement->lines->where('agreement_unit_id', $unit->agreement_unit_id)->where('type', DeductionType::UnpaidRent);
            foreach ($rentLines as $line) {
                $invoiceLine = InvoiceLine::query()->findOrFail($line->invoice_line_id);
                $take = min($left, Fils::fromDecimal($line->amount), $invoiceLine->balanceFils());
                if ($take > 0) {
                    $plan[$invoiceLine->id] = ($plan[$invoiceLine->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
            foreach ($deductionLines->where('agreement_unit_id', $unit->agreement_unit_id) as $invoiceLine) {
                $take = min($left, $invoiceLine->balanceFils());
                if ($take > 0) {
                    $plan[$invoiceLine->id] = ($plan[$invoiceLine->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
            $heldBy[$unit->id] = $held;
            $useBy[$unit->id] = $held - $left;
        }

        // 3. One deposit_applied payment for Σ applied, allocated to the named lines.
        $total = array_sum($useBy);
        $payment = null;
        if ($total > 0) {
            $payment = $this->post->handle($customer, [
                'received_on' => now('Asia/Bahrain')->toDateString(),
                'method' => PaymentMethod::DepositApplied,
                'amount' => Fils::toDecimal($total),
                'reference' => __('Deposit settlement'),
            ], [], $approver);
            $this->apply->toLines($payment, $plan, $approver);
        }

        // 4. applied movements, per-unit amounts, number and status.
        $now = now();
        $refund = 0;
        foreach ($settlement->units as $unit) {
            $held = $heldBy[$unit->id];
            $use = $useBy[$unit->id];
            if ($use > 0) {
                DepositMovement::create([
                    'agreement_unit_id' => $unit->agreement_unit_id,
                    'owner_contract_id' => DepositMovement::ownerContractFor($unit->agreement_unit_id),
                    'type' => DepositMovementType::Applied,
                    'amount' => Fils::toDecimal(-$use),
                    'source_type' => 'deposit_settlement',
                    'source_id' => $settlement->id,
                    'posted_at' => $now,
                ]);
            }
            $unit->forceFill([
                'held_amount' => Fils::toDecimal($held),
                'applied_amount' => Fils::toDecimal($use),
                'refund_amount' => Fils::toDecimal($held - $use),
            ])->save();
            $refund += $held - $use;
        }

        $settlement->forceFill([
            'number' => ($this->next)(NumberSequenceKey::DepositSettlement),
            'approved_at' => $now,
            'deductions_invoice_id' => $invoice?->id,
            'payment_id' => $payment?->id,
            'status' => $refund === 0 ? DepositSettlementStatus::Completed : DepositSettlementStatus::Approved,
        ])->save();
    }

    private function deductionsInvoice(DepositSettlement $settlement, int $agreementId, int $customerId, User $approver): ?Invoice
    {
        $lines = $settlement->lines->filter(fn (DepositSettlementLine $l) => $l->type !== DeductionType::UnpaidRent)->values();
        if ($lines->isEmpty()) {
            return null;
        }

        $aus = AgreementUnit::query()->with('unit')->whereKey($lines->pluck('agreement_unit_id'))->get()->keyBy('id');
        $lastDay = $aus->map(fn (AgreementUnit $au) => $au->move_out_date && $au->move_out_date->greaterThan($au->end_date) ? $au->move_out_date : $au->end_date)->sortDesc()->firstOrFail();
        $net = $lines->sum(fn (DepositSettlementLine $l) => Fils::fromDecimal($l->amount));

        $invoice = (new Invoice)->forceFill([
            'type' => InvoiceType::Manual,
            'customer_id' => $customerId,
            'agreement_id' => $agreementId,
            'issue_date' => now('Asia/Bahrain')->toDateString(),
            'due_date' => $lastDay->toDateString(), // the §4.6 attribution date for these lines
            'status' => InvoiceStatus::Draft,
            'subtotal' => Fils::toDecimal($net),
            'tax_total' => '0.000',
            'total' => Fils::toDecimal($net),
            'created_by' => $approver->id,
        ]);
        $invoice->save();

        foreach ($lines as $l) {
            $au = AgreementUnit::query()->with('unit')->findOrFail($l->agreement_unit_id);
            $invoice->lines()->create([
                'agreement_unit_id' => $au->id,
                'unit_id' => $au->unit_id,
                'charge_type' => $l->type->chargeType(),
                'description' => $l->description,
                'net' => $l->amount,
                'tax_category' => $au->unit->effectiveTaxCategory(),
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'total' => $l->amount,
            ]);
        }

        if (! $this->issue->handle($invoice, $approver, autoAllocate: false)) {
            throw ValidationException::withMessages(['approval' => __('The deductions invoice is held back: an owner contract covering the unit is waiting for approval.')]);
        }

        return $invoice->refresh();
    }
}
