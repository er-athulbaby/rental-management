<?php

namespace App\Actions\Agreements;

use App\Models\Agreement;
use App\Models\AgreementUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Spec §5.5, on submit and again on approval. Run inside the caller's transaction, before anything else. */
final class EnsureNoAgreementOverlap
{
    public function handle(Agreement $agreement): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('EnsureNoAgreementOverlap must run inside the caller\'s transaction.');
        }

        $lines = DB::table('agreement_units')->where('agreement_id', $agreement->id)->orderBy('unit_id')
            ->get(['unit_id', 'start_date', 'end_date']);

        // Ascending id order, so concurrent submissions for the same units queue instead of deadlocking.
        $units = DB::table('units')->whereIn('id', $lines->pluck('unit_id'))->orderBy('id')->lockForUpdate()->get(['id', 'code', 'blocked']);

        $blocked = $units->filter(fn ($u) => (bool) $u->blocked)->pluck('code')->all();
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
                ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$today, $line->start_date])
                ->lockForUpdate()
                ->first(['u.code', 'a.id', 'a.number']);

            if ($clash) {
                $clashes[] = $clash->code.' ('.($clash->number ?? __('pending #:id', ['id' => $clash->id])).')';
            }
        }

        if ($clashes !== []) {
            throw ValidationException::withMessages(['units' => __('Already let on these dates: :list.', ['list' => implode(', ', $clashes)])]);
        }
    }
}
