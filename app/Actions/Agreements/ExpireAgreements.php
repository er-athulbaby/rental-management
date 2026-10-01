<?php

namespace App\Actions\Agreements;

use App\Actions\Deposits\CreateDepositSettlement;
use App\Enums\AgreementStatus;
use App\Enums\AmendmentStatus;
use App\Enums\AmendmentType;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\DepositSettlementUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The 02:00 job (spec §12) and the closing check (spec §5.4), which RecordMoveOut also runs. Idempotent.
 * Occupancy ends at the later of move_out_date and end_date; the job drafts the settlement the day after (spec §5.9).
 */
final class ExpireAgreements
{
    public function __construct(private CreateDepositSettlement $settlement) {}

    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        $changed = 0;
        $due = Agreement::query()->whereIn('status', [AgreementStatus::Active, AgreementStatus::Expired])->where('end_date', '<', $today)->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            $changed += DB::transaction(fn () => $this->checkOne($id) !== null ? 1 : 0, attempts: 3);
        }

        // Units whose occupancy ended before today and have no settlement yet (spec §5.9).
        $ended = AgreementUnit::query()
            ->whereNotNull('move_out_date')
            ->whereRaw('GREATEST(move_out_date, end_date) < ?', [$today])
            ->whereNotIn('id', DepositSettlementUnit::query()->select('agreement_unit_id'))
            ->whereHas('agreement', fn ($q) => $q->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval]))
            ->get()->groupBy('agreement_id');
        foreach ($ended as $agreementId => $units) {
            DB::transaction(function () use ($agreementId, $units) {
                $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreementId);
                // ponytail: the agreement's creator stands in as the settlement's creator for the nightly job; a system user arrives with M5's jobs.
                $this->settlement->handle($agreement, array_values($units->map(fn (AgreementUnit $au): int => $au->id)->all()), User::query()->findOrFail($agreement->created_by));
            }, attempts: 3);
        }

        return $changed;
    }

    /** Spec §5.4. Inside the caller's transaction. Returns the new status, or null when nothing changes. */
    public function checkOne(int $agreementId): ?AgreementStatus
    {
        $agreement = Agreement::query()->lockForUpdate()->findOrFail($agreementId);
        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Expired], true)
            || $agreement->end_date->toDateString() >= now('Asia/Bahrain')->toDateString()) {
            return null;
        }

        $units = $agreement->agreementUnits()->get(['id', 'unit_id', 'move_out_date']);
        $renewal = Agreement::query()->where('previous_agreement_id', $agreement->id)->where('status', AgreementStatus::Active)->first();
        $carried = $renewal ? $renewal->agreementUnits()->pluck('unit_id')->all() : [];

        $done = $units->every(fn ($au) => $au->move_out_date !== null || in_array($au->unit_id, $carried, true));

        $status = match (true) {
            $done && $renewal !== null && $units->contains(fn ($au) => in_array($au->unit_id, $carried, true)) => AgreementStatus::Renewed,
            $done && $agreement->amendments()->where('type', AmendmentType::Terminate)->where('status', AmendmentStatus::Approved)->exists() => AgreementStatus::Terminated,
            $done => AgreementStatus::Closed,
            $agreement->status === AgreementStatus::Active => AgreementStatus::Expired,
            default => null,
        };

        if ($status !== null) {
            $agreement->forceFill(['status' => $status])->save();
        }

        return $status;
    }
}
