<?php

namespace App\Actions\Agreements;

use App\Actions\Approvals\RequestApproval;
use App\Agreements\ContractMerge;
use App\Enums\AgreementStatus;
use App\Enums\ApprovalAction;
use App\Enums\ChargeType;
use App\Models\Agreement;
use App\Models\Approval;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitAgreement
{
    public function __construct(
        private EnsureNoAgreementOverlap $overlap,
        private ContractMerge $merge,
        private RequestApproval $request,
    ) {}

    public function handle(User $actor, Agreement $agreement): Approval
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $agreement) {
            $locked = $this->overlap->handle($agreement); // the unit locks come first (spec §5.5)

            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            // The lines could have been saved between the unit locks and the agreement lock: what was checked must be what is submitted.
            $current = array_values(DB::table('agreement_units')->where('agreement_id', $agreement->id)->orderBy('unit_id')->lockForUpdate()->pluck('unit_id')->map(fn ($id) => (int) $id)->all());
            if ($current !== $locked) {
                throw ValidationException::withMessages(['units' => __('The agreement changed while submitting; try again.')]);
            }

            $agreement->load(['agreementUnits.charges', 'customer', 'contractTemplate.clauses']); // plain reads only after every lock

            if ($agreement->status !== AgreementStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('Only a draft can be submitted.')]);
            }
            if ($agreement->agreementUnits->isEmpty()) {
                throw ValidationException::withMessages(['units' => __('Add at least one unit.')]);
            }
            if ($agreement->agreementUnits->contains(fn ($au) => $au->charges->where('type', ChargeType::Rent)->count() !== 1)) {
                throw ValidationException::withMessages(['units' => __('Each unit needs exactly one rent charge.')]);
            }

            $template = $agreement->contractTemplate?->active ? $agreement->contractTemplate : ContractTemplate::defaultTemplate()?->load('clauses');
            if (! $template) {
                throw ValidationException::withMessages(['contract_template_id' => __('No active contract template. Ask Admin to set one up.')]);
            }

            // Clauses first: agreement_clauses accept writes only while the agreement is draft (spec §8.5).
            $agreement->forceFill(['contract_template_id' => $template->id])->save();
            $agreement->clauses()->delete();
            foreach ($this->merge->clauses($agreement, $template) as $clause) {
                $agreement->clauses()->create($clause);
            }

            $agreement->forceFill(['status' => AgreementStatus::PendingApproval])->save();

            return $this->request->handle($actor, $agreement, ApprovalAction::AgreementActivation);
        });
    }
}
