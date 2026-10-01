<?php

namespace App\Approvals;

use App\Enums\DisbursementStatus;
use App\Models\Approval;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Spec §8.3 item 9: a payment out with no approved source. */
final class PaymentOut implements ApprovalHandler
{
    public function creatorId(Approval $approval): int
    {
        return Disbursement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->move($approval, DisbursementStatus::Approved);
    }

    public function reject(Approval $approval, User $approver): void
    {
        $this->move($approval, DisbursementStatus::Rejected);
    }

    private function move(Approval $approval, DisbursementStatus $to): void
    {
        $out = Disbursement::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        if ($out->status !== DisbursementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This payment out is no longer waiting for approval.')]);
        }
        $out->forceFill(['status' => $to])->save();
    }

    public function summary(Approval $approval): string
    {
        $out = Disbursement::query()->findOrFail($approval->approvable_id);

        return __('Pay :amount BHD to :payee by :method. Reason: :reason', [
            'amount' => $out->amount, 'payee' => $out->payee()->name_en, 'method' => $out->method->label(), 'reason' => $out->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('disbursements.show', $approval->approvable_id);
    }
}
