<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §4 (plan ruling 6): Admin reopens the latest finished reconciliation of an account. Its matches
 * stay; its entries are no longer reconciled (the reversal guard counts finished reconciliations only).
 */
final class ReopenBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $reconciliation) {
            LedgerAccount::query()->lockForUpdate()->findOrFail($reconciliation->ledger_account_id); // the account row first, so every read below sees what the lock waited for (an account never changes)
            $rec = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            if ($rec->status !== 'finished') {
                throw ValidationException::withMessages(['reopen' => __('Only a finished reconciliation can be reopened.')]);
            }
            if (BankReconciliation::query()->where('ledger_account_id', $rec->ledger_account_id)->where('end_date', '>', $rec->end_date->toDateString())->exists()) {
                throw ValidationException::withMessages(['reopen' => __('Only the latest reconciliation of an account can be reopened.')]);
            }
            $old = ['finished_by' => $rec->finished_by, 'finished_at' => $rec->finished_at?->toIso8601String()];
            $rec->forceFill(['status' => 'open', 'finished_by' => null, 'finished_at' => null, 'reopened_by' => $actor->id, 'reopened_at' => now()])->save();
            Audit::log('bank.reconciliation_reopened', $rec, old: $old, causer: $actor);

            return $rec;
        });
    }
}
