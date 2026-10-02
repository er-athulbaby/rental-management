<?php

namespace App\Actions\Deposits;

use App\Actions\Billing\BuildCreditNote;
use App\Actions\Billing\IssueCreditNote;
use App\Actions\Billing\IssueInvoice;
use App\Actions\NextDocumentNumber;
use App\Actions\Payments\ApplyToInvoices;
use App\Actions\Payments\PostPayment;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\DeductionType;
use App\Enums\DepositMovementType;
use App\Enums\DepositSettlementStatus;
use App\Enums\InvoiceChargeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\NumberSequenceKey;
use App\Enums\PaymentMethod;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Cheque;
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
 * Spec §7.7 on approval, in one transaction. Locks: customer → settlement → held cheques on unissued deposit invoices → named rent lines → (unissued deposit invoices, cancelled) → (system credit note for
 * any unpaid deposit balance: its deposit lines → deposit invoice) → (deductions invoice, new) → invoices → payment; the
 * customer lock serialises the second batch of lines. Credit auto-allocation is suppressed: the deductions invoice issues with
 * autoAllocate false, and the deposit_applied payment is allocated explicitly.
 */
final class ApproveDepositSettlement
{
    public function __construct(
        private BuildCreditNote $buildCreditNote,
        private IssueCreditNote $issueCreditNote,
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

        // The customer lock is the first read: a locking read, so the later reads see everything committed before it.
        $customer = Customer::query()
            ->whereIn('id', Agreement::query()->select('customer_id')->whereKey($pending->agreement_id))
            ->lockForUpdate()->firstOrFail();
        $agreement = Agreement::query()->findOrFail($pending->agreement_id);
        $settlement = DepositSettlement::query()->lockForUpdate()->with(['units', 'lines'])->findOrFail($pending->id);
        if ($settlement->status !== DepositSettlementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This settlement is no longer waiting for approval.')]);
        }

        // Plan ruling 9: a deposit invoice not yet issued (normally held back by a pending owner contract, §4.6) must not bill
        // a settled unit later. Its held cheques are locked first, before any invoice line or invoice (as ClearCheque and
        // RebillAgreement: cheques → invoices).
        $settled = $settlement->units->pluck('agreement_unit_id')->all();
        $heldBackIds = Invoice::query()->where('type', InvoiceType::Deposit)->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Scheduled])
            ->whereHas('lines', fn ($q) => $q->whereIn('agreement_unit_id', $settled))->orderBy('id')->pluck('id');
        $heldCheques = Cheque::query()->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Held)
            ->whereIn('invoice_id', $heldBackIds)->orderBy('id')->lockForUpdate()->get();

        // Lock the named unpaid_rent lines (ascending) before planning; the caps come from these rows.
        $rentLines = InvoiceLine::query()
            ->whereKey($settlement->lines->where('type', DeductionType::UnpaidRent)->pluck('invoice_line_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // 0. A held-back deposit invoice wholly on settled units is cancelled, and its held cheques are left to return
        // (as RebillAgreement); a mixed one is refused. Locked after the rent lines above (§7.2: invoice lines → invoices).
        $heldBack = Invoice::query()->whereKey($heldBackIds)->orderBy('id')->lockForUpdate()->with('lines')->get();
        foreach ($heldBack as $invoice) {
            if ($invoice->lines->contains(fn ($l) => ! in_array($l->agreement_unit_id, $settled, true))) {
                throw ValidationException::withMessages(['approval' => __('Deposit invoice :n also covers other units and is held back by a pending owner contract: decide that contract first, then approve this settlement.', ['n' => $invoice->label()])]);
            }
            $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();
        }
        foreach ($heldCheques as $cheque) {
            $cheque->forceFill(['invoice_id' => null, 'to_return' => true])->save();
        }

        // An unpaid deposit balance on a settled unit is credited, so no later payment can reach it (as TransferDeposits).
        $number = ($this->next)(NumberSequenceKey::DepositSettlement);
        $unpaid = InvoiceLine::query()->whereIn('agreement_unit_id', $settlement->units->pluck('agreement_unit_id'))
            ->where('charge_type', InvoiceChargeType::Deposit)
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Issued))->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (InvoiceLine $l) => $l->balanceFils() > 0);
        foreach ($unpaid->groupBy('invoice_id') as $invoiceId => $lines) {
            $cn = $this->buildCreditNote->handle(Invoice::query()->findOrFail($invoiceId), array_values($lines->map(fn (InvoiceLine $l) => [$l, $l->balanceFils()])->all()),
                __('Deposit closed by settlement :n', ['n' => $number]), $approver);
            $cn->forceFill(['status' => InvoiceStatus::PendingApproval])->save();
            $this->issueCreditNote->handle($cn, $approver);
        }

        // 1. Non-rent deductions → one issued manual invoice, attributed on the unit's last day (plan ruling 7).
        $invoice = $this->deductionsInvoice($settlement, $agreement->id, $customer->id, $approver);
        $deductionLines = InvoiceLine::query()->where('invoice_id', $invoice?->id)->orderBy('id')->get(); // none when there is no invoice

        // 2. Per unit: held, capped deductions, applied (re-read under the locks above).
        $plan = [];
        $heldBy = [];
        $useBy = [];
        foreach ($settlement->units as $unit) {
            $held = DepositMovement::heldFils($unit->agreement_unit_id, lock: true);
            $left = $held;
            $unitRent = $settlement->lines->where('agreement_unit_id', $unit->agreement_unit_id)->where('type', DeductionType::UnpaidRent);
            foreach ($unitRent as $line) {
                $invoiceLine = $rentLines->get($line->invoice_line_id) ?? throw new LogicException("Invoice line {$line->invoice_line_id} is missing.");
                $take = min($left, Fils::fromDecimal($line->amount), $invoiceLine->balanceFils() - ($plan[$invoiceLine->id] ?? 0));
                if ($take > 0) {
                    $plan[$invoiceLine->id] = ($plan[$invoiceLine->id] ?? 0) + $take;
                    $left -= $take;
                }
            }
            foreach ($deductionLines->where('agreement_unit_id', $unit->agreement_unit_id) as $invoiceLine) {
                $take = min($left, $invoiceLine->balanceFils() - ($plan[$invoiceLine->id] ?? 0));
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
            'number' => $number,
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
