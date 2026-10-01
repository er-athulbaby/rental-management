<?php

namespace App\Actions\Agreements;

use App\Actions\Deposits\CreateDepositSettlement;
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

/** Spec §5.9. Without a unit, every unit not yet moved out is recorded. Ends neither billing nor occupancy before end_date. */
final class RecordMoveOut
{
    public function __construct(private CreateDepositSettlement $settlement, private ExpireAgreements $close) {}

    public function handle(User $actor, Agreement $agreement, ?AgreementUnit $unit, string $moveOutDate, ?string $readings, ?string $notes): void
    {
        $inScope = $unit
            ? $unit->agreement_id === $agreement->id && Unit::query()->visibleTo($actor)->whereKey($unit->unit_id)->exists()
            : AgreementPolicy::allUnitsInScope($actor, $agreement);
        if (! $actor->can(PermissionName::AgreementsManage) || ! $inScope) {
            throw new AuthorizationException;
        }

        Validator::make(['move_out_date' => $moveOutDate, 'readings' => $readings, 'notes' => $notes], [
            'move_out_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'readings' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        DB::transaction(function () use ($actor, $agreement, $unit, $moveOutDate, $readings, $notes) {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)) {
                throw ValidationException::withMessages(['move_out_date' => __('Move-outs are recorded on active or expired agreements.')]);
            }

            $targets = AgreementUnit::query()->where('agreement_id', $agreement->id)
                ->when($unit, fn ($q, AgreementUnit $u) => $q->whereKey($u->id), fn ($q) => $q->whereNull('move_out_date'))
                ->orderBy('id')->lockForUpdate()->get();
            if ($targets->isEmpty() || $targets->contains(fn (AgreementUnit $au) => $au->move_out_date !== null)) {
                throw ValidationException::withMessages(['move_out_date' => __('That move-out is already recorded.')]);
            }
            if ($targets->contains(fn (AgreementUnit $au) => $moveOutDate < $au->start_date->toDateString())) {
                throw ValidationException::withMessages(['move_out_date' => __('The move-out cannot be before the unit\'s start date.')]);
            }

            foreach ($targets as $au) {
                $au->forceFill(['move_out_date' => $moveOutDate, 'move_out_readings' => $readings, 'move_out_notes' => $notes, 'move_out_recorded_by' => $actor->id])->save();
            }

            // Occupancy has ended for units already past their end date: their settlement is drafted now (spec §5.9).
            $ended = array_values($targets->filter(fn (AgreementUnit $au) => $au->end_date->toDateString() <= $moveOutDate)->map(fn (AgreementUnit $au): int => $au->id)->all());
            if ($ended !== []) {
                $this->settlement->handle($agreement, $ended, $actor);
            }

            $this->close->checkOne($agreement->id); // spec §5.4: the closing check runs on each move-out
        }, attempts: 3);
    }
}
