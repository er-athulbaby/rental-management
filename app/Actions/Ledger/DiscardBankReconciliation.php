<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Plan ruling 6: an open reconciliation (a mistyped end date or closing balance) is deleted with its matches. */
final class DiscardBankReconciliation
{
    public function handle(User $actor, BankReconciliation $reconciliation): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $reconciliation) {
            $rec = BankReconciliation::lockOpen($reconciliation, 'discard');
            $matches = BankMatch::query()->where('bank_reconciliation_id', $rec->id)->delete();
            Audit::log('bank.reconciliation_discarded', $rec, old: ['end_date' => $rec->end_date->toDateString(), 'closing' => $rec->closing_balance, 'matches' => $matches],
                causer: $actor);
            $rec->delete();
        });
    }
}
