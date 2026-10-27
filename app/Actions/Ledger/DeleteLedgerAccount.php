<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec §3: an account with lines cannot be deleted (the foreign key agrees); neither can a default or a rule's account. */
final class DeleteLedgerAccount
{
    public function handle(User $actor, LedgerAccount $account): void
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $error = match (true) {
            $account->system_key !== null => __('A default account cannot be deleted. Deactivate it instead.'),
            $account->lines()->exists() => __('This account has postings. Deactivate it instead.'),
            DB::table('ledger_rules')->where('ledger_account_id', $account->id)->exists() => __('Posting rules use this account. Point them at another account first.'),
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['accounts' => $error]);
        }
        Audit::log('ledger.account_deleted', $account, properties: ['code' => $account->code, 'name_en' => $account->name_en], causer: $actor);
        $account->delete();
    }
}
