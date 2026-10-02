<?php

namespace App\Approvals;

use App\Actions\OwnerContracts\RescheduleOwnerPayables;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Spec §8.3 item 7 (early termination). The payload carries terminated_on and termination_reason. */
final class OwnerContractTermination implements ApprovalHandler
{
    public function __construct(private RescheduleOwnerPayables $reschedule) {}

    public function creatorId(Approval $approval): int
    {
        return OwnerContract::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $contract = OwnerContract::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        $on = CarbonImmutable::parse((string) ($approval->payload['terminated_on'] ?? ''));

        if ($contract->status !== OwnerContractStatus::Active || $on->greaterThanOrEqualTo($contract->end_date)) {
            throw ValidationException::withMessages(['approval' => __('The contract is no longer active, or already ends by that date.')]);
        }

        $contract->forceFill([
            'terminated_on' => $on,
            'termination_reason' => $approval->payload['termination_reason'] ?? '',
            'end_date' => $on,
        ])->save();
        $this->reschedule->handle($contract, $on);
    }

    /** A rejected request about an existing record leaves it unchanged (spec §8.3). */
    public function reject(Approval $approval, User $approver): void {}

    public function summary(Approval $approval): string
    {
        $contract = OwnerContract::with('owner:id,name_en')->findOrFail($approval->approvable_id);

        return __('Terminate :label (:owner) on :date, instead of :end', [
            'label' => $contract->label(),
            'owner' => $contract->owner?->name_en,
            'date' => CarbonImmutable::parse((string) ($approval->payload['terminated_on'] ?? ''))->format('d/m/Y'),
            'end' => $contract->end_date->format('d/m/Y'),
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('owner-contracts.show', $approval->approvable_id);
    }
}
