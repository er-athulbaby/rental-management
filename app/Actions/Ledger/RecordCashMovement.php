<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Ledger\Sources;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §3 (plan rulings 8 and 10): Finance records a transfer between two money accounts, a bank
 * charge paid from one, or bank interest received into one, on an open date. Poster posts it after commit.
 */
final class RecordCashMovement
{
    /** @param  mixed  $data  kind, from_account_id, to_account_id, amount, date, reference, note */
    public function handle(User $actor, mixed $data): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $kind = CashMovementKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $needsFrom = $kind !== CashMovementKind::BankInterest;
        $needsTo = $kind !== CashMovementKind::BankCharge;
        $v = Validator::make($data, [
            'kind' => ['required', 'string', Rule::enum(CashMovementKind::class)],
            'from_account_id' => [Rule::requiredIf($needsFrom), 'nullable', 'integer'],
            'to_account_id' => [Rule::requiredIf($needsTo), 'nullable', 'integer'],
            'amount' => ['required', 'string', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $kind = CashMovementKind::from((string) $v['kind']);
        $from = $needsFrom ? (int) $v['from_account_id'] : null;
        $to = $needsTo ? (int) $v['to_account_id'] : null;
        if ($from !== null && $from === $to) {
            throw ValidationException::withMessages(['to_account_id' => __('Choose two different money accounts.')]);
        }

        return DB::transaction(function () use ($actor, $v, $kind, $from, $to) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            PostManualJournal::openDate($settings, (string) $v['date'], 'date');
            foreach (['from_account_id' => $from, 'to_account_id' => $to] as $field => $id) {
                if ($id !== null && ! LedgerAccount::query()->whereKey($id)->where('is_money', true)->where('active', true)->exists()) {
                    throw ValidationException::withMessages([$field => __('Choose an active money account.')]);
                }
            }

            $movement = (new CashMovement)->forceFill([
                'kind' => $kind,
                'from_account_id' => $from,
                'to_account_id' => $to,
                'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
                'date' => (string) $v['date'],
                'reference' => trim((string) $v['reference']),
                'note' => filled($v['note'] ?? null) ? trim((string) $v['note']) : null,
                'created_by' => $actor->id,
            ]);
            $movement->save();
            Sources::assertPostable('cash_movement', $movement->id, 'kind');
            Audit::log('ledger.cash_movement_recorded', $movement, new: ['kind' => $kind->value, 'amount' => $movement->amount, 'date' => (string) $v['date']], causer: $actor);

            return $movement;
        });
    }
}
