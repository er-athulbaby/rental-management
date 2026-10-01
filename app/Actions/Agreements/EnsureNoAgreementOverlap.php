<?php

namespace App\Actions\Agreements;

use App\Models\Agreement;
use App\Models\AgreementUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Spec §5.5, on submit and again on approval. Run inside the caller's transaction, before anything else except the customer lock. */
final class EnsureNoAgreementOverlap
{
    /**
     * @param  list<int>|null  $onlyBlockedCheck  unit ids whose blocked flag matters (null = every unit of the agreement)
     * @return list<int> the unit ids that were locked, so a caller can detect the lines changing afterwards
     */
    public function handle(Agreement $agreement, ?array $onlyBlockedCheck = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureNoAgreementOverlap must run inside the caller\'s transaction.');
        }

        // Locking reads only, unit rows first in ascending id order (concurrent submissions queue instead of
        // deadlocking). A plain read before these locks would fix the InnoDB snapshot and hide later commits.
        $units = DB::table('units')
            ->whereIn('id', fn ($q) => $q->select('unit_id')->from('agreement_units')->where('agreement_id', $agreement->id))
            ->orderBy('id')->lockForUpdate()->get(['id', 'code', 'blocked']);

        $lines = DB::table('agreement_units')->where('agreement_id', $agreement->id)->orderBy('unit_id')
            ->lockForUpdate()->get(['unit_id', 'start_date', 'end_date']);
        $blocked = $units->filter(fn ($u) => (bool) $u->blocked && ($onlyBlockedCheck === null || in_array($u->id, $onlyBlockedCheck, true)))->pluck('code')->all();
        if ($blocked !== []) {
            throw ValidationException::withMessages(['units' => __('These units are blocked: :list.', ['list' => implode(', ', $blocked)])]);
        }

        $today = now('Asia/Bahrain')->toDateString();
        $clashes = [];

        foreach ($lines as $line) {
            $clash = DB::table('agreement_units as au')
                ->join('agreements as a', 'a.id', '=', 'au.agreement_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->where('au.unit_id', $line->unit_id)
                ->where('a.id', '<>', $agreement->id)
                ->where('a.status', '<>', 'draft')          // drafts never hold units
                ->whereNull('a.deleted_at')
                ->where('au.start_date', '<=', $line->end_date)
                // Spec §5.8: the agreement being renewed holds a carried unit only to its end_date (or a later move-out).
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('a.id', '<>', $agreement->previous_agreement_id ?? 0)
                        ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$today, $line->start_date]))
                    ->orWhere(fn ($q) => $q->where('a.id', $agreement->previous_agreement_id ?? 0)
                        ->whereRaw('GREATEST(au.end_date, COALESCE(au.move_out_date, au.end_date)) >= ?', [$line->start_date])))
                ->lockForUpdate()
                ->first(['u.code', 'a.id', 'a.number']);

            if ($clash) {
                $clashes[] = $clash->code.' ('.($clash->number ?? __('pending #:id', ['id' => $clash->id])).')';
            }
        }

        if ($clashes !== []) {
            throw ValidationException::withMessages(['units' => __('Already let on these dates: :list.', ['list' => implode(', ', $clashes)])]);
        }

        return array_values($units->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
