<?php

namespace App\Actions\OwnerStatements;

use App\Actions\Approvals\RequestApproval;
use App\Billing\OwnerStatementCalculator;
use App\Enums\ApprovalAction;
use App\Enums\OwnerStatementStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.9: Finance submits a reviewed draft; only after the contract's previous statement is finalised. */
final class SubmitOwnerStatement
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, OwnerStatement $statement): Approval
    {
        if (! $actor->can('submit', $statement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $statement) {
            OwnerContract::query()->lockForUpdate()->findOrFail($statement->owner_contract_id);
            $locked = OwnerStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($locked->status !== OwnerStatementStatus::Draft) {
                throw ValidationException::withMessages(['statement' => __('Only a draft statement can be submitted.')]);
            }
            $previous = $locked->previous();
            if ($previous !== null && $previous->status !== OwnerStatementStatus::Finalised) {
                throw ValidationException::withMessages(['statement' => __('Finalise the previous statement (:m) first.', ['m' => $previous->period_start->format('M Y')])]);
            }

            OwnerStatementCalculator::apply($locked); // its opening now comes from a finalised statement
            $locked->forceFill(['status' => OwnerStatementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $locked, ApprovalAction::OwnerStatementFinalisation);
        }, attempts: 3);
    }
}
