<?php

namespace App\Approvals;

use App\Actions\Deposits\ApproveDepositSettlement;
use App\Enums\DepositSettlementStatus;
use App\Models\Approval;
use App\Models\DepositSettlement;
use App\Models\User;
use App\Support\Fils;

/** Spec §8.3 item 4. */
final class DepositSettlementApproval implements ApprovalHandler
{
    public function __construct(private ApproveDepositSettlement $approve) {}

    public function creatorId(Approval $approval): int
    {
        return DepositSettlement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->approve->handle(DepositSettlement::query()->findOrFail($approval->approvable_id), $approver);
    }

    public function reject(Approval $approval, User $approver): void
    {
        DepositSettlement::query()->lockForUpdate()->findOrFail($approval->approvable_id)
            ->forceFill(['status' => DepositSettlementStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $s = DepositSettlement::query()->with(['agreement.customer', 'units', 'lines'])->findOrFail($approval->approvable_id);
        $held = $s->units->sum(fn ($u) => Fils::fromDecimal($u->held_amount));
        $deductions = $s->lines->sum(fn ($l) => Fils::fromDecimal($l->amount));

        return __('Deposit settlement for :agreement (:customer): held :held BHD, deductions :ded BHD.', [
            'agreement' => $s->agreement->label(), 'customer' => $s->agreement->customer->name_en,
            'held' => Fils::toDecimal($held), 'ded' => Fils::toDecimal($deductions),
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('approvals.index'); // Task 6 points this at the settlement page
    }
}
