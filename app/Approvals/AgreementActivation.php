<?php

namespace App\Approvals;

use App\Actions\Agreements\ActivateAgreement;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\User;
use App\Support\Fils;

/** Spec §8.3 item 1. */
final class AgreementActivation implements ApprovalHandler
{
    public function __construct(private ActivateAgreement $activate) {}

    private function agreement(Approval $approval): Agreement
    {
        return Agreement::query()->findOrFail($approval->approvable_id);
    }

    public function creatorId(Approval $approval): int
    {
        return $this->agreement($approval)->created_by;
    }

    public function approve(Approval $approval, User $approver): void
    {
        $this->activate->handle($this->agreement($approval), $approver);
    }

    /** Back to draft, then the frozen clauses go (they accept writes only while draft); the comment stays on the approval. */
    public function reject(Approval $approval, User $approver): void
    {
        $agreement = Agreement::query()->lockForUpdate()->findOrFail($approval->approvable_id);
        $agreement->forceFill(['status' => AgreementStatus::Draft])->save();
        $agreement->clauses()->delete();
    }

    public function summary(Approval $approval): string
    {
        $agreement = Agreement::with(['customer', 'agreementUnits.charges'])->findOrFail($approval->approvable_id);
        $list = $agreement->listRentFils();
        $discount = $agreement->discountFils();

        return __('Agreement :label: :customer, :units unit(s), :start to :end. Rent :rent BHD/month (list :list; discount :disc BHD, :pct%).', [
            'label' => $agreement->label(),
            'customer' => $agreement->customer->name_en,
            'units' => $agreement->agreementUnits->count(),
            'start' => $agreement->start_date->format('d/m/Y'),
            'end' => $agreement->end_date->format('d/m/Y'),
            'rent' => Fils::toDecimal($agreement->monthlyRentFils()),
            'list' => Fils::toDecimal($list),
            'disc' => Fils::toDecimal($discount),
            'pct' => $list > 0 ? number_format($discount * 100 / $list, 1) : '0.0',
        ]);
    }

    public function url(Approval $approval): string
    {
        return route('agreements.show', $approval->approvable_id);
    }
}
