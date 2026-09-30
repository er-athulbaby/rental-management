<?php

namespace App\Actions\Agreements;

use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Unit;
use App\Models\User;
use App\Policies\AgreementPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Spec §5.4 "Record notice": no approval; changes neither end_date nor billing. */
final class RecordNotice
{
    public function handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $noticeDate, string $plannedExitDate): void
    {
        $inScope = $unit
            ? $unit->agreement_id === $agreement->id && Unit::query()->visibleTo($actor)->whereKey($unit->unit_id)->exists()
            : AgreementPolicy::allUnitsInScope($actor, $agreement);

        if (! $actor->can(PermissionName::AgreementsManage) || ! $inScope) {
            throw new AuthorizationException;
        }

        $dates = Validator::make(['notice_date' => $noticeDate, 'planned_exit_date' => $plannedExitDate], [
            'notice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'planned_exit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:notice_date'],
        ])->validate();

        DB::transaction(function () use ($actor, $agreement, $unit, $dates) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
                throw ValidationException::withMessages(['notice_date' => __('Notice can be recorded only on an active or expired agreement.')]);
            }

            if ($unit) {
                // Re-read: a retried attempt must not trust the caller's model, whose dirty state the first attempt consumed.
                AgreementUnit::query()->lockForUpdate()->findOrFail($unit->id)->forceFill(['planned_exit_date' => $dates['planned_exit_date']])->save();
            } else {
                $agreement->forceFill($dates)->save();
            }

            Audit::log('agreement.notice_recorded', $agreement, properties: [...$dates, 'agreement_unit_id' => $unit?->id], causer: $actor);
        }, attempts: 3);
    }
}
