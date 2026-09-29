<?php

namespace App\Actions\OwnerContracts;

use App\Actions\Approvals\RequestApproval;
use App\Enums\ApprovalAction;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitOwnerContract
{
    public function __construct(private EnsureNoOverlap $overlap, private RequestApproval $request) {}

    public function handle(User $actor, OwnerContract $contract): Approval
    {
        if (! $actor->can('update', $contract)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $contract) {
            $this->overlap->handle($contract); // the unit locks come first (spec §4.5)

            $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

            if ($contract->status !== OwnerContractStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be submitted.')]);
            }

            $contract->forceFill(['status' => OwnerContractStatus::PendingApproval])->save();

            return $this->request->handle($actor, $contract, ApprovalAction::OwnerContractActivation);
        });
    }
}
