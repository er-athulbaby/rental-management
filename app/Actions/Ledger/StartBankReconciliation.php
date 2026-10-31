<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankReconciliation;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3 (plan ruling 4): Finance starts a reconciliation of a money account to a statement end date and
 * closing balance. The opening balance is the latest reconciliation's closing balance; the first one is typed. One open per
 * account; end dates move forward only.
 */
final class StartBankReconciliation
{
    /** A signed amount with up to 3 decimals (an overdrawn account's balance is negative). */
    private const string MONEY = 'regex:/^-?\d{1,12}(\.\d{1,3})?$/';

    /** @param  mixed  $data  ledger_account_id, end_date, closing_balance, opening_balance (used only for the account's first reconciliation) */
    public function handle(User $actor, mixed $data): BankReconciliation
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $amount = __('Enter an amount with up to 3 decimals, with a minus sign if it is overdrawn.');
        $v = Validator::make($data, [
            'ledger_account_id' => ['required', 'integer'],
            'end_date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'closing_balance' => ['required', 'string', self::MONEY],
            'opening_balance' => ['nullable', 'string', self::MONEY],
        ], ['closing_balance.regex' => $amount, 'opening_balance.regex' => $amount])->validate();

        return DB::transaction(function () use ($actor, $v) {
            $account = LedgerAccount::query()->whereKey((int) $v['ledger_account_id'])->where('is_money', true)->where('active', true)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['ledger_account_id' => __('Choose an active money account.')]);
            $last = BankReconciliation::query()->where('ledger_account_id', $account->id)->orderByDesc('end_date')->first();
            if ($last?->status === 'open') {
                throw ValidationException::withMessages(['ledger_account_id' => __('This account already has an open reconciliation. Finish or discard it first.')]);
            }
            if ($last !== null && (string) $v['end_date'] <= $last->end_date->toDateString()) {
                throw ValidationException::withMessages(['end_date' => __('Choose an end date after :date, the end date of the last reconciliation.',
                    ['date' => $last->end_date->format('d/m/Y')])]);
            }
            if ($last === null && blank($v['opening_balance'] ?? null)) {
                throw ValidationException::withMessages(['opening_balance' => __('Enter the statement opening balance: this is the first reconciliation of this account.')]);
            }

            $rec = (new BankReconciliation)->forceFill([
                'ledger_account_id' => $account->id,
                'end_date' => (string) $v['end_date'],
                'opening_balance' => $last->closing_balance ?? Fils::toDecimal(Fils::fromDecimal((string) $v['opening_balance'])),
                'closing_balance' => Fils::toDecimal(Fils::fromDecimal((string) $v['closing_balance'])),
                'status' => 'open',
                'created_by' => $actor->id,
            ]);
            $rec->save();
            Audit::log('bank.reconciliation_started', $rec, new: ['account' => $account->code, 'end_date' => (string) $v['end_date'],
                'opening' => $rec->opening_balance, 'closing' => $rec->closing_balance], causer: $actor);

            return $rec;
        });
    }
}
