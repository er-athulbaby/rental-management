<?php

namespace App\Actions\Agreements;

use App\Actions\Billing\CreateDepositInvoice;
use App\Actions\Billing\RebillAgreement;
use App\Billing\RebillResult;
use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\AgreementUnit;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spec §5.7 on approval. Internal: inside DecideApproval's transaction. Locks: customer → units (overlap, add_unit) →
 * agreement → agreement units → (RebillAgreement: cheques → lines → invoices).
 */
final class ApplyAmendment
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private RebillAgreement $rebill,
        private CreateDepositInvoice $deposit,
    ) {}

    public function handle(AgreementAmendment $pending, User $approver): RebillResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyAmendment must run inside the caller\'s transaction.');
        }

        $agreement = Agreement::query()->findOrFail($pending->agreement_id);
        Customer::query()->lockForUpdate()->findOrFail($agreement->customer_id); // first lock (spec §7.2)
        $amendment = AgreementAmendment::query()->lockForUpdate()->findOrFail($pending->id);
        if ($amendment->status !== AmendmentStatus::PendingApproval) {
            throw ValidationException::withMessages(['approval' => __('This amendment is no longer waiting for approval.')]);
        }
        $amendment->forceFill(['status' => AmendmentStatus::Approved])->save();
        $effective = $amendment->effective_date;
        $newUnitId = null;

        if ($amendment->type === AmendmentType::AddUnit) {
            $data = (array) $amendment->data;
            $au = (new AgreementUnit)->forceFill([
                'agreement_id' => $agreement->id,
                'amendment_id' => $amendment->id,
                'unit_id' => (int) $data['unit_id'],
                'list_rent' => Unit::query()->findOrFail((int) $data['unit_id'])->list_rent,
                'deposit_amount' => $data['deposit_amount'],
                'start_date' => $effective->toDateString(),
                'end_date' => $agreement->end_date->toDateString(),
            ]);
            $au->save();
            foreach ((array) $data['charges'] as $charge) {
                $au->charges()->create($charge);
            }
            $this->overlap->handle($agreement); // spec §5.5: locks the unit rows, then the overlap query as a locking read
            $newUnitId = $au->id;
        }

        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
        if ($agreement->status !== AgreementStatus::Active) {
            throw ValidationException::withMessages(['approval' => __('The agreement is no longer active.')]);
        }

        $units = AgreementUnit::query()->where('agreement_id', $agreement->id)->orderBy('id')->lockForUpdate()->get();
        if ($amendment->type === AmendmentType::ReleaseUnit) {
            $units->firstWhere('id', $amendment->agreement_unit_id)?->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
        }
        if ($amendment->type === AmendmentType::Terminate) {
            foreach ($units->filter(fn (AgreementUnit $au) => $au->end_date->greaterThan($effective)) as $au) {
                $au->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
            }
            $agreement->forceFill(['end_date' => $effective->toDateString(), 'planned_exit_date' => $effective->toDateString()])->save();
        }

        $result = $this->rebill->handle($agreement, $effective, $approver, __(':type (:date): :reason', [
            'type' => $amendment->type->label(), 'date' => $effective->format('d/m/Y'), 'reason' => $amendment->reason,
        ]));

        if ($newUnitId !== null) {
            $deposit = Fils::fromDecimal((string) ((array) $amendment->data)['deposit_amount']);
            $this->deposit->handle($agreement->refresh(), $approver, [$newUnitId => $deposit]);
        }

        $amendment->forceFill(['applied_at' => now()])->save();

        return $result;
    }
}
