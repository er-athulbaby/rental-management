<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\DepositSettlementUnit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §5.8: a draft renewal of a subset of the units, charges and deposits copied, starting the day after the old end. */
final class RenewAgreement
{
    public function __construct(private SaveAgreement $save) {}

    /** @param  list<int>  $unitIds */
    public function handle(User $actor, Agreement $old, array $unitIds, string $endDate): Agreement
    {
        if (! $actor->can('create', Agreement::class) || ! AgreementPolicy::allUnitsInScope($actor, $old)) {
            throw new AuthorizationException;
        }
        $old->load('agreementUnits.charges');
        $carried = $old->agreementUnits->whereIn('unit_id', $unitIds);
        if ($unitIds === [] || $carried->count() !== count(array_unique($unitIds))) {
            throw ValidationException::withMessages(['units' => __('Choose units of this agreement to carry into the renewal.')]);
        }

        $units = [];
        foreach ($carried as $au) {
            $charges = [];
            foreach ($au->charges as $c) {
                $charges[] = ['type' => $c->type->value, 'description' => $c->description, 'monthly_amount' => $c->monthly_amount, 'tax_category' => $c->tax_category->value];
            }
            $units[] = ['unit_id' => $au->unit_id, 'deposit_amount' => $au->deposit_amount, 'charges' => $charges];
        }

        return DB::transaction(function () use ($actor, $old, $carried, $units, $endDate) {
            $locked = Agreement::query()->lockForUpdate()->findOrFail($old->id); // serialises two renewals of the same agreement
            if (! in_array($locked->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
                throw ValidationException::withMessages(['units' => __('Only an active or expired agreement can be renewed.')]);
            }
            $busy = AgreementUnit::query()->whereIn('id', $carried->pluck('id'))->orderBy('id')->lockForUpdate()->get()
                ->contains(fn (AgreementUnit $au) => $au->move_out_date !== null || DepositSettlementUnit::query()->where('agreement_unit_id', $au->id)->exists());
            if ($busy) {
                throw ValidationException::withMessages(['units' => __('A unit that has moved out or is in a deposit settlement cannot be renewed.')]);
            }
            if (Agreement::query()->where('previous_agreement_id', $old->id)->whereIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval, AgreementStatus::Active])->exists()) {
                throw ValidationException::withMessages(['units' => __('This agreement already has a renewal.')]);
            }

            $draft = $this->save->handle($actor, null, [
                'customer_id' => $old->customer_id,
                'start_date' => $old->end_date->addDay()->toDateString(),
                'end_date' => $endDate,
                'frequency' => $old->frequency->value,
                'billing_day' => $old->billing_day,
                'grace_days' => $old->grace_days,
                'notice_period_days' => $old->notice_period_days,
                'units' => $units,
            ]);
            $draft->forceFill(['previous_agreement_id' => $old->id])->save(); // allowed while draft (spec §8.5)

            return $draft;
        }, attempts: 3);
    }
}
