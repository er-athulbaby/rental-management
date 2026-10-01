<?php

namespace App\Approvals;

use App\Enums\ChequeStatus;
use App\Enums\DepositMovementType;
use App\Enums\DepositSettlementStatus;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use App\Models\Approval;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Disbursement;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Validation\ValidationException;

/** Spec §7.3, §8.3 item 6 (payments out). Runs inside DecideApproval's transaction. */
final class PaymentOutReversal implements ApprovalHandler
{
    public function creatorId(Approval $approval): int
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return $out->recorded_by ?? $out->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);
        if ($out->payee_type === PayeeType::Customer) {
            Customer::query()->lockForUpdate()->findOrFail($out->payee_id); // first lock (spec §7.2): credit changes
        }
        $cheque = $out->cheque_id !== null ? Cheque::query()->lockForUpdate()->findOrFail($out->cheque_id) : null;

        $this->reopen($out, $approver); // Task 6: deposit refunds write the opposite movement and reopen the settlement

        $out = Disbursement::query()->lockForUpdate()->findOrFail($out->id); // disbursements lock last
        if ($out->status !== DisbursementStatus::Paid) {
            throw ValidationException::withMessages(['approval' => __('This payment out is no longer paid.')]);
        }
        $out->forceFill(['status' => DisbursementStatus::Reversed, 'reversed_at' => now()])->save();

        // Spec §7.4: an issued cheque not yet presented is cancelled; one the bank already cleared stays cleared.
        if ($cheque?->status === ChequeStatus::Issued) {
            $cheque->forceFill(['status' => ChequeStatus::Cancelled])->save();
        }
    }

    /** Spec §7.3: reopen the source. A credit refund needs nothing (its amount counts as credit again once reversed). */
    private function reopen(Disbursement $out, User $approver): void
    {
        if ($out->purpose !== DisbursementPurpose::DepositRefund) {
            return;
        }

        $settlement = DepositSettlement::query()->lockForUpdate()->findOrFail($out->source_id);
        foreach (DepositMovement::query()->where('source_type', 'disbursement')->where('source_id', $out->id)->where('type', DepositMovementType::Refunded)->get() as $m) {
            DepositMovement::create([
                'agreement_unit_id' => $m->agreement_unit_id,
                'owner_contract_id' => $m->owner_contract_id,
                'type' => DepositMovementType::Refunded,
                'amount' => Fils::toDecimal(-Fils::fromDecimal($m->amount)), // the opposite entry (spec §7.3)
                'source_type' => 'disbursement_reversal',
                'source_id' => $out->id,
                'posted_at' => now(),
            ]);
        }
        if ($settlement->status === DepositSettlementStatus::Completed) {
            $settlement->forceFill(['status' => DepositSettlementStatus::Approved])->save();
        }
    }

    /** A request about an existing record: rejecting leaves it unchanged (spec §8.3). */
    public function reject(Approval $approval, User $approver): void {}

    public function summary(Approval $approval): string
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return __('Reverse payment out :n: :amount BHD to :payee (:purpose). Reason: :reason', [
            'n' => $out->number, 'amount' => $out->amount, 'payee' => $out->payee()->name_en,
            'purpose' => $out->purpose->label(), 'reason' => $approval->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('disbursements.show', $approval->approvable_id);
    }
}
