<?php

namespace App\Actions\Disbursements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DisbursementStatus;
use App\Models\Approval;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §7.3: Finance asks, with a reason; nothing changes until Management approves (§8.3 item 6). */
final class RequestDisbursementReversal
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, Disbursement $disbursement, string $reason): Approval
    {
        if (! $actor->can('reverse', $disbursement)) {
            throw new AuthorizationException;
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reversalReason' => __('Give the reason for the reversal.')]);
        }

        return DB::transaction(function () use ($actor, $disbursement, $reason) {
            $locked = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($locked->status !== DisbursementStatus::Paid) {
                throw ValidationException::withMessages(['reversalReason' => __('Only a paid payment out can be reversed.')]);
            }

            return $this->request->handle($actor, $locked, ApprovalAction::PaymentOutReversal, trim($reason));
        }, attempts: 3);
    }
}
