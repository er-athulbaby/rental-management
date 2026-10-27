<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3: a deactivated account takes no new postings, so an account a posting rule uses stays active. */
final class SetLedgerAccountActive
{
    public function handle(User $actor, LedgerAccount $account, bool $active): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        if (! $active && DB::table('ledger_rules')->where('ledger_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['accounts' => __('Posting rules use this account. Point them at another account first.')]);
        }
        if (! $active && DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.kind', 'opening')->where('l.ledger_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['accounts' => __('This account has an opening balance: set it to zero first.')]);
        }
        $account->forceFill(['active' => $active])->save();
        Audit::log($active ? 'ledger.account_activated' : 'ledger.account_deactivated', $account, causer: $actor);
    }
}
