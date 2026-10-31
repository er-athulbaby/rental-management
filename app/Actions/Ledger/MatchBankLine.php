<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\BankEntries;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Bank reconciliation spec §3 (plan ruling 3): Finance matches one statement line of the open reconciliation to one or more
 * unmatched ledger entries on the account, dated by the end date, whose live amounts total the line exactly.
 */
final class MatchBankLine
{
    /** @param  mixed  $entries  entry keys ("type:id", BankEntries::key) */
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId, mixed $entries): void
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $keys = BankEntries::parseKeys($entries)
            ?? throw ValidationException::withMessages(['picked' => __('Choose the ledger entries that make up this line.')]);

        DB::transaction(function () use ($actor, $reconciliation, $lineId, $keys) {
            $rec = BankReconciliation::lockOpen($reconciliation->id, 'picked');
            $line = $rec->unmatchedLine($lineId);
            $found = BankEntries::find($rec->ledger_account_id, $rec->end_date->toDateString(), $keys);
            if (count($found) !== count($keys)) {
                throw ValidationException::withMessages(['picked' => __('A chosen entry is not on this account by the end date.')]);
            }
            $taken = BankMatch::query()->where('ledger_account_id', $rec->ledger_account_id)->where(function (Builder $q) use ($keys) {
                foreach ($keys as [$type, $id]) {
                    $q->orWhere(fn (Builder $w) => $w->where('entry_type', $type)->where('entry_id', $id));
                }
            })->exists();
            if ($taken) {
                throw ValidationException::withMessages(['picked' => __('A chosen entry is already matched to another statement line.')]);
            }
            $total = array_sum(array_map(fn (stdClass $e) => Fils::fromDecimal((string) $e->amount), $found));
            if ($total !== Fils::fromDecimal($line->amount)) {
                throw ValidationException::withMessages(['picked' => __('The chosen entries total :total; the statement line is :amount.',
                    ['total' => Fils::toDecimal($total), 'amount' => $line->amount])]);
            }

            foreach ($found as $e) {
                (new BankMatch)->forceFill(['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => $line->id, 'ledger_account_id' => $rec->ledger_account_id,
                    'entry_type' => (string) $e->entry_type, 'entry_id' => (int) $e->entry_id, 'amount' => (string) $e->amount, 'created_by' => $actor->id])->save();
            }
            Audit::log('bank.line_matched', $rec, properties: ['line' => $line->id, 'entries' => array_keys($found)], causer: $actor);
        });
    }
}
