<?php

namespace App\Approvals;

use App\Actions\Agreements\ApplyAmendment;
use App\Enums\AmendmentStatus;
use App\Models\AgreementAmendment;
use App\Models\Approval;
use App\Models\User;

/** Spec §8.3 items 2 (add or release a unit) and 3 (early termination). */
final class AgreementAmendmentApproval implements ApprovalHandler
{
    public function __construct(private ApplyAmendment $apply) {}

    public function creatorId(Approval $approval): int
    {
        return AgreementAmendment::query()->findOrFail($approval->approvable_id)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->apply->handle(AgreementAmendment::query()->findOrFail($approval->approvable_id), $approver);
    }

    public function reject(Approval $approval, User $approver): void
    {
        AgreementAmendment::query()->lockForUpdate()->findOrFail($approval->approvable_id)
            ->forceFill(['status' => AmendmentStatus::Draft])->save();
    }

    public function summary(Approval $approval): string
    {
        $m = AgreementAmendment::query()->with('agreementUnit.unit')->findOrFail($approval->approvable_id);
        $agreement = $m->agreement()->with('customer')->firstOrFail();
        $what = match ($m->type->value) {
            'release_unit' => __('release unit :u', ['u' => $m->agreementUnit?->unit->code]),
            'add_unit' => __('add a unit at :rent BHD/month', ['rent' => collect((array) ($m->data['charges'] ?? []))->firstWhere('type', 'rent')['monthly_amount'] ?? '?']),
            default => __('terminate the agreement'),
        };

        return __(':agreement (:customer): :what from :date. Reason: :reason', [
            'agreement' => $agreement->label(), 'customer' => $agreement->customer->name_en,
            'what' => $what, 'date' => $m->effective_date->format('d/m/Y'), 'reason' => $m->reason,
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('agreements.show', AgreementAmendment::query()->findOrFail($approval->approvable_id)->agreement_id);
    }
}
