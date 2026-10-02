<?php

namespace App\Approvals;

use App\Actions\NextDocumentNumber;
use App\Billing\OwnerStatementCalculator;
use App\Enums\NumberSequenceKey;
use App\Enums\OwnerChargeType;
use App\Enums\OwnerStatementStatus;
use App\Jobs\StoreOwnerStatement;
use App\Models\Approval;
use App\Models\OwnerCharge;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.9, §8.3 item 8. Runs inside DecideApproval's transaction. */
final class OwnerStatementFinalisation implements ApprovalHandler
{
    public function __construct(private NextDocumentNumber $next) {}

    public function creatorId(Approval $approval): int
    {
        return OwnerStatement::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $statement = $this->lock($approval);
        OwnerStatementCalculator::apply($statement);
        $statement->forceFill([
            'status' => OwnerStatementStatus::Finalised,
            'number' => ($this->next)(NumberSequenceKey::OwnerStatement),
            'finalised_by' => $approver->id,
            'finalised_at' => now(),
        ])->save();

        $fee = Fils::fromDecimal($statement->fee_amount);
        if ($fee > 0) {
            $tax = Fils::fromDecimal($statement->fee_tax);
            OwnerCharge::create([
                'owner_contract_id' => $statement->owner_contract_id,
                'owner_statement_id' => $statement->id,
                'type' => OwnerChargeType::ManagementFee,
                'net' => Fils::toDecimal($fee),
                'tax_amount' => Fils::toDecimal($tax),
                'amount' => Fils::toDecimal(-($fee + $tax)),
                'posted_at' => $statement->cutoff_at,
                'created_by' => $approver->id,
            ]);
        }

        DB::afterCommit(fn () => StoreOwnerStatement::dispatch($statement->id, $approver->id));
    }

    public function reject(Approval $approval, User $approver): void
    {
        $this->lock($approval)->forceFill(['status' => OwnerStatementStatus::Draft])->save();
    }

    private function lock(Approval $approval): OwnerStatement
    {
        $statement = OwnerStatement::query()->findOrFail($approval->approvable_id);
        OwnerContract::query()->lockForUpdate()->findOrFail($statement->owner_contract_id);
        $statement = OwnerStatement::query()->lockForUpdate()->findOrFail($statement->id);
        if ($statement->status !== OwnerStatementStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This statement is no longer waiting for approval.')]);
        }

        return $statement;
    }

    public function summary(Approval $approval): string
    {
        $s = OwnerStatement::query()->with('contract.owner')->findOrFail($approval->approvable_id);

        return __('Finalise the :m statement for :owner (:c): fee :fee BHD + VAT :tax, closing balance :close BHD.', [
            'm' => $s->period_start->format('M Y'), 'owner' => $s->contract->owner?->name_en, 'c' => $s->contract->number,
            'fee' => $s->fee_amount, 'tax' => $s->fee_tax, 'close' => $s->closing_balance,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('owner-statements.show', $approval->approvable_id);
    }
}
