<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\AmendmentType;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Spec §5.7 state rules, checked when an amendment is saved and again against locked rows when it is applied. */
final class AmendmentRules
{
    /**
     * @param  Collection<int, AgreementUnit>  $units  the agreement's units
     * @param  string|null  $key  validation key for every message (null = the field each rule is about)
     */
    public static function check(Agreement $agreement, Collection $units, AmendmentType $type, string $effective, ?int $agreementUnitId, ?int $unitId, ?string $key = null): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$key ?? $field => $message]);

        if ($agreement->status !== AgreementStatus::Active) {
            $fail('type', __('Only an active agreement can be amended.'));
        }
        if ($effective < $agreement->start_date->toDateString() || $effective >= $agreement->end_date->toDateString()) {
            $fail('effective_date', __('Choose a date after the start and before the end of the agreement.'));
        }
        if ($type === AmendmentType::ReleaseUnit) {
            $au = $units->firstWhere('id', $agreementUnitId);
            if ($au === null || $effective < $au->start_date->toDateString() || $effective >= $au->end_date->toDateString()) {
                $fail('agreement_unit_id', __('Choose a unit of this agreement that is let past that date.'));
            }
            if ($units->filter(fn (AgreementUnit $u) => $u->end_date->toDateString() > $effective)->count() < 2) {
                $fail('agreement_unit_id', __('This is the last unit: terminate the agreement instead.'));
            }
        }
        if ($type === AmendmentType::AddUnit && $units->contains('unit_id', $unitId)) {
            $fail('unit_id', __('This unit is already on the agreement.'));
        }
    }
}
