<?php

namespace App\Actions\Deposits;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\DepositSettlementStatus;
use App\Models\Approval;
use App\Models\DepositSettlement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec §7.7, §8.3 item 4. */
final class SubmitDepositSettlement
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, DepositSettlement $draft): Approval
    {
        return DB::transaction(function () use ($actor, $draft) {
            $settlement = DepositSettlement::query()->lockForUpdate()->findOrFail($draft->id);
            if (! $actor->can('update', $settlement)) {
                throw new AuthorizationException;
            }
            $settlement->forceFill(['status' => DepositSettlementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $settlement, ApprovalAction::DepositSettlement);
        }, attempts: 3);
    }
}
