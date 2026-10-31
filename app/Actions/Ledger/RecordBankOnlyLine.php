<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\CashMovementKind;
use App\Enums\PermissionName;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\CashMovement;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3 (plan ruling 8): a statement line with nothing in the ledger (a bank charge, bank interest or a
 * transfer) is recorded through the existing RecordCashMovement on the line's date, and matched to it, in one transaction.
 */
final class RecordBankOnlyLine
{
    public function __construct(private RecordCashMovement $record) {}

    /** @param  mixed  $otherAccountId  the other money account of a transfer */
    public function handle(User $actor, BankReconciliation $reconciliation, mixed $lineId, mixed $kind, mixed $otherAccountId): CashMovement
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $kind = is_string($kind) ? CashMovementKind::tryFrom($kind) : null;
        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => __('Choose what this line is.')]);
        }

        return DB::transaction(function () use ($actor, $reconciliation, $lineId, $kind, $otherAccountId) {
            LedgerSetting::query()->lockForUpdate()->firstOrFail(); // plan ruling 10: the settings row before the account row, the order Poster takes
            $rec = BankReconciliation::lockOpen($reconciliation, 'line');
            $line = $rec->unmatchedLine($lineId);
            $fils = Fils::fromDecimal($line->amount);
            if (($kind === CashMovementKind::BankCharge && $fils > 0) || ($kind === CashMovementKind::BankInterest && $fils < 0)) {
                throw ValidationException::withMessages(['kind' => __('A bank charge is money out; bank interest is money in.')]);
            }
            $account = $rec->ledger_account_id;
            $movement = $this->record->handle($actor, [
                'kind' => $kind->value,
                'from_account_id' => match ($kind) {
                    CashMovementKind::BankCharge => $account,
                    CashMovementKind::BankInterest => null,
                    CashMovementKind::Transfer => $fils < 0 ? $account : $otherAccountId,
                },
                'to_account_id' => match ($kind) {
                    CashMovementKind::BankCharge => null,
                    CashMovementKind::BankInterest => $account,
                    CashMovementKind::Transfer => $fils > 0 ? $account : $otherAccountId,
                },
                'amount' => Fils::toDecimal(abs($fils)),
                'date' => $line->date->toDateString(),
                'reference' => mb_substr(filled($line->reference) ? (string) $line->reference : $line->description, 0, 100) ?: 'BANK-'.$line->id,
            ]);
            (new BankMatch)->forceFill(['bank_reconciliation_id' => $rec->id, 'bank_statement_line_id' => $line->id, 'ledger_account_id' => $account,
                'entry_type' => 'cash_movement', 'entry_id' => $movement->id, 'amount' => $line->amount, 'created_by' => $actor->id])->save();
            Audit::log('bank.line_movement_recorded', $rec, properties: ['line' => $line->id, 'movement' => $movement->id, 'kind' => $kind->value], causer: $actor);

            return $movement;
        });
    }
}
