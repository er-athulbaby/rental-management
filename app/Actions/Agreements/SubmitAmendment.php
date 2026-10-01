<?php

namespace App\Actions\Agreements;

use App\Actions\Approvals\RequestApproval;
use App\Enums\AmendmentStatus;
use App\Enums\PermissionName;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.7, §8.3 items 2–3. */
final class SubmitAmendment
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, AgreementAmendment $draft): Approval
    {
        $agreement = $draft->agreement()->firstOrFail();
        if (! $actor->can(PermissionName::AgreementsManage) || ! AgreementPolicy::allUnitsInScope($actor, $agreement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $draft) {
            $amendment = AgreementAmendment::query()->lockForUpdate()->findOrFail($draft->id);
            if ($amendment->status !== AmendmentStatus::Draft) {
                throw ValidationException::withMessages(['type' => __('Only a draft amendment can be submitted.')]);
            }
            $amendment->forceFill(['status' => AmendmentStatus::PendingApproval])->save();

            return $this->request->handle($actor, $amendment, $amendment->type->approvalAction(), $amendment->reason);
        }, attempts: 3);
    }
}
