<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Enums\ChargeType;
use App\Enums\PermissionName;
use App\Enums\TaxCategory;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\Unit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §5.7: a draft amendment of an active agreement. Every unit it touches must be in the actor's scope (§8.2). */
final class SaveAmendment
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Agreement $agreement, ?AgreementAmendment $draft, array $data): AgreementAmendment
    {
        if (! $actor->can(PermissionName::AgreementsManage) || ! AgreementPolicy::allUnitsInScope($actor, $agreement)) {
            throw new AuthorizationException;
        }

        $v = Validator::make($data, [
            'type' => ['required', Rule::enum(AmendmentType::class)],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            'agreement_unit_id' => ['required_if:type,release_unit', 'nullable', 'integer'],
            'unit_id' => ['required_if:type,add_unit', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'deposit_amount' => ['nullable', Fils::rule()],
            'charges' => ['required_if:type,add_unit', 'array'],
            'charges.*.type' => ['required', Rule::enum(ChargeType::class)],
            'charges.*.description' => ['nullable', 'string', 'max:150'],
            'charges.*.monthly_amount' => ['required', Fils::rule()],
            'charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->validate();

        $type = AmendmentType::from($v['type']);
        $effective = $v['effective_date'];

        if ($agreement->status !== AgreementStatus::Active) {
            throw ValidationException::withMessages(['type' => __('Only an active agreement can be amended.')]);
        }
        if ($effective < $agreement->start_date->toDateString() || $effective >= $agreement->end_date->toDateString()) {
            throw ValidationException::withMessages(['effective_date' => __('Choose a date after the start and before the end of the agreement.')]);
        }

        $agreementUnitId = null;
        $payload = null;
        if ($type === AmendmentType::ReleaseUnit) {
            $au = $agreement->agreementUnits()->find((int) $v['agreement_unit_id']);
            if ($au === null || $effective < $au->start_date->toDateString() || $effective >= $au->end_date->toDateString()) {
                throw ValidationException::withMessages(['agreement_unit_id' => __('Choose a unit of this agreement that is let past that date.')]);
            }
            if ($agreement->agreementUnits()->where('end_date', '>', $effective)->count() < 2) {
                throw ValidationException::withMessages(['agreement_unit_id' => __('This is the last unit: terminate the agreement instead.')]);
            }
            $agreementUnitId = $au->id;
        }
        if ($type === AmendmentType::AddUnit) {
            $unitId = (int) $v['unit_id'];
            if (! Unit::query()->visibleTo($actor)->whereKey($unitId)->exists()) {
                throw new AuthorizationException;
            }
            if ($agreement->agreementUnits()->where('unit_id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => __('This unit is already on the agreement.')]);
            }
            $rents = array_filter($v['charges'] ?? [], fn (array $c) => $c['type'] === ChargeType::Rent->value);
            if (count($rents) !== 1 || Fils::fromDecimal((string) array_values($rents)[0]['monthly_amount']) === 0) {
                throw ValidationException::withMessages(['charges' => __('The unit needs exactly one rent charge above zero.')]);
            }
            $payload = [
                'unit_id' => $unitId,
                'deposit_amount' => Fils::toDecimal(Fils::fromDecimal((string) ($v['deposit_amount'] ?? '0'))),
                'charges' => array_map(fn (array $c) => [
                    'type' => $c['type'], 'description' => $c['description'] ?? null,
                    'monthly_amount' => Fils::toDecimal(Fils::fromDecimal((string) $c['monthly_amount'])), 'tax_category' => $c['tax_category'],
                ], array_values($v['charges'])),
            ];
        }

        return DB::transaction(function () use ($actor, $agreement, $draft, $type, $effective, $agreementUnitId, $payload, $v) {
            $amendment = $draft ? AgreementAmendment::query()->lockForUpdate()->findOrFail($draft->id) : new AgreementAmendment;
            if ($draft && ($amendment->status !== AmendmentStatus::Draft || $amendment->agreement_id !== $agreement->id)) {
                throw ValidationException::withMessages(['type' => __('Only a draft amendment can be edited.')]);
            }

            $amendment->forceFill([
                'agreement_id' => $agreement->id,
                'type' => $type,
                'effective_date' => $effective,
                'agreement_unit_id' => $agreementUnitId,
                'data' => $payload,
                'reason' => trim((string) $v['reason']),
                'status' => AmendmentStatus::Draft,
                'created_by' => $amendment->created_by ?? $actor->id,
            ])->save();

            return $amendment;
        }, attempts: 3);
    }
}
