<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankReconciliation;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §4 (plan rulings 4 and 9): Finance finishes only when every statement line to the end date is
 * matched, no matched entry changed, the matched lines explain the closing balance and the difference is 0.000. Not while a
 * ledger rebuild is queued or running. The period need not be locked.
 */
final class FinishBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $reconciliation) {
            $rec = BankReconciliation::lockOpen($reconciliation, 'finish');
            if (LedgerSetting::current()->rebuilding()) {
                throw ValidationException::withMessages(['finish' => __('Wait for the rebuild to finish.')]);
            }
            $f = BankEntries::figures($rec);
            $reasons = array_values(array_filter([
                $f['unmatched_lines'] > 0 ? __('Statement lines up to the end date not matched: :n.', ['n' => $f['unmatched_lines']]) : null,
                $f['changed'] > 0 ? __('Matched entries that changed in the ledger since they were matched: :n. Remove those matches and match again.', ['n' => $f['changed']]) : null,
                $f['covered'] ? null : __('The opening balance plus the matched statement lines is :sum, not the closing balance :closing.',
                    ['sum' => Fils::toDecimal($f['opening'] + $f['movement']), 'closing' => Fils::toDecimal($f['closing'])]),
                $f['difference'] === 0 ? null : __('The difference is :difference; it must be 0.000.', ['difference' => Fils::toDecimal($f['difference'])]),
            ]));
            if ($reasons !== []) {
                throw ValidationException::withMessages(['finish' => $reasons]);
            }

            $rec->forceFill(['status' => 'finished', 'finished_by' => $actor->id, 'finished_at' => now()])->save();
            Audit::log('bank.reconciliation_finished', $rec, new: ['in_transit' => Fils::toDecimal($f['in_transit']), 'outstanding' => Fils::toDecimal($f['outstanding']),
                'ledger' => Fils::toDecimal($f['ledger']), 'closing' => $rec->closing_balance], causer: $actor);

            return $rec;
        });
    }
}
