<?php

namespace App\Approvals;

use App\Actions\OwnerContracts\ActivateOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Models\Approval;
use App\Models\OwnerContract;
use App\Models\User;

/** Spec §8.3 item 7 (activation). */
final class OwnerContractActivation implements ApprovalHandler
{
    public function __construct(private ActivateOwnerContract $activate) {}

    private function contract(Approval $approval): OwnerContract
    {
        return OwnerContract::query()->findOrFail($approval->approvable_id);
    }

    public function creatorId(Approval $approval): int
    {
        return $this->contract($approval)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->activate->handle($this->contract($approval));
    }

    /** Back to draft; the comment stays on the approval (spec §8.3). */
    public function reject(Approval $approval, User $approver): void
    {
        $this->contract($approval)->forceFill(['status' => OwnerContractStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $contract = OwnerContract::with(['owner:id,name_en', 'building:id,name'])->withCount('units')->findOrFail($approval->approvable_id);

        return __(':type contract :label: :owner, :building, :units unit(s), :start to :end', [
            'type' => str($contract->type->value)->headline()->toString(),
            'label' => $contract->label(),
            'owner' => $contract->owner?->name_en,
            'building' => $contract->building?->name,
            'units' => $contract->units_count,
            'start' => $contract->start_date->format('d/m/Y'),
            'end' => $contract->end_date->format('d/m/Y'),
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('owner-contracts.show', $approval->approvable_id);
    }
}
