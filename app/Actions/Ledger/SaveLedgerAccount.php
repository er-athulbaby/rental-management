<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §3: Admin adds and renames accounts. Once an account has postings its type stays (the reports would change shape). */
final class SaveLedgerAccount
{
    /** @param  array<string, mixed>  $data  code, name_en, name_ar, type */
    public function handle(User $actor, ?LedgerAccount $account, array $data): LedgerAccount
    {
        if (! $actor->can(PermissionName::LedgerAdmin)) {
            throw new AuthorizationException;
        }
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/', Rule::unique('ledger_accounts', 'code')->ignore($account?->id)],
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', Rule::enum(LedgerAccountType::class)],
        ], ['code.regex' => __('Use up to 20 letters, digits, dots or dashes.')])->validate();

        return DB::transaction(function () use ($actor, $account, $v) {
            $account = $account === null ? new LedgerAccount : LedgerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $old = $account->exists ? ['code' => $account->code, 'name_en' => $account->name_en, 'name_ar' => $account->name_ar, 'type' => $account->type->value] : [];
            if ($account->exists && $account->type->value !== $v['type'] && $account->lines()->exists()) {
                throw ValidationException::withMessages(['type' => __('This account has postings, so its type cannot change.')]);
            }
            $new = ['code' => trim((string) $v['code']), 'name_en' => trim((string) $v['name_en']), 'name_ar' => trim((string) $v['name_ar']), 'type' => (string) $v['type']];
            $account->fill($new)->save();
            Audit::log('ledger.account_saved', $account, $old, $new, causer: $actor);

            return $account;
        });
    }
}
