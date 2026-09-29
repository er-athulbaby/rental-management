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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RequestOwnerContractTermination
{
    public function __construct(private RequestApproval $request) {}

    public function handle(User $actor, OwnerContract $contract, string $terminatedOn, string $reason): Approval
    {
        if (! $actor->can('update', $contract)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(
            ['terminated_on' => $terminatedOn, 'termination_reason' => trim($reason)],
            [
                'terminated_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$contract->start_date->toDateString(), 'before:'.$contract->end_date->toDateString()],
                'termination_reason' => ['required', 'string', 'max:500'],
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $contract, $data) {
            $contract = OwnerContract::query()->lockForUpdate()->findOrFail($contract->id);

            if ($contract->status !== OwnerContractStatus::Active || $contract->terminated_on !== null) {
                throw ValidationException::withMessages(['terminated_on' => __('Only an active contract that is not already terminating can be terminated early.')]);
            }

            return $this->request->handle($actor, $contract, ApprovalAction::OwnerContractTermination, $data['termination_reason'], $data);
        });
    }
}
