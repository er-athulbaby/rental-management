<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bank reconciliation spec §3 (plan ruling 3): while the reconciliation is open, Finance removes all of a line's matches. */
final class UnmatchBankLine
{
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $reconciliation, $lineId) {
            $rec = BankReconciliation::lockOpen($reconciliation, 'line');
            $id = is_int($lineId) || (is_string($lineId) && ctype_digit($lineId) && strlen($lineId) < 19) ? (int) $lineId : 0;
            $matches = BankMatch::query()->where('bank_reconciliation_id', $rec->id)->where('bank_statement_line_id', $id)->get();
            if ($matches->isEmpty()) {
                throw ValidationException::withMessages(['line' => __('This statement line has no match in this reconciliation.')]);
            }
            BankMatch::query()->whereKey($matches->modelKeys())->delete();
            Audit::log('bank.line_unmatched', $rec, properties: ['line' => $id,
                'entries' => $matches->map(fn (BankMatch $m) => BankEntries::key($m->entry_type, $m->entry_id))->all()], causer: $actor);
        });
    }
}
