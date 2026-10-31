<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation spec §3–§4 (plan rulings 4 and 6). Status "open" or "finished"; open_account_id is a generated column
 * the Actions never write.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property CarbonImmutable $end_date
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string $status
 * @property int $created_by
 * @property int|null $finished_by
 * @property CarbonImmutable|null $finished_at
 * @property int|null $reopened_by
 * @property CarbonImmutable|null $reopened_at
 * @property-read LedgerAccount $account
 * @property-read User|null $finisher
 */
class BankReconciliation extends Model
{
    protected $guarded = ['id', 'open_account_id'];

    protected function casts(): array
    {
        return ['end_date' => 'immutable_date', 'opening_balance' => 'decimal:3', 'closing_balance' => 'decimal:3', 'finished_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }

    /**
     * Plan ruling 10: locks the account's ledger_accounts row (every bank reconciliation writer does), then returns the
     * reconciliation re-read under the lock, refusing on $field one that is not open. Call inside a transaction.
     */
    public static function lockOpen(int $id, string $field): self
    {
        LedgerAccount::query()->lockForUpdate()->findOrFail(self::query()->findOrFail($id)->ledger_account_id);
        $rec = self::query()->findOrFail($id);
        if ($rec->status !== 'open') {
            throw ValidationException::withMessages([$field => __('This reconciliation is finished.')]);
        }

        return $rec;
    }

    /** The statement line $lineId on this account, dated by the end date and not matched yet; a message on "line" otherwise. */
    public function unmatchedLine(mixed $lineId): BankStatementLine
    {
        $id = is_int($lineId) || (is_string($lineId) && ctype_digit($lineId) && strlen($lineId) < 19) ? (int) $lineId : 0;
        $line = BankStatementLine::query()->where('ledger_account_id', $this->ledger_account_id)->find($id)
            ?? throw ValidationException::withMessages(['line' => __('Choose a statement line of this account.')]);
        if ($line->date->toDateString() > $this->end_date->toDateString()) {
            throw ValidationException::withMessages(['line' => __('This statement line is after the end date.')]);
        }
        if (BankMatch::query()->where('bank_statement_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages(['line' => __('This statement line is already matched.')]);
        }

        return $line;
    }
}
