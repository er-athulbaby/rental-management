<?php

namespace App\Models;

use App\Enums\CashMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger money screens spec §3 (plan ruling 8). Written only by RecordCashMovement and ReverseMoneyEntry; never updated
 * or deleted (two triggers). A reversal is its own row pointing at the movement it reverses.
 *
 * @property int $id
 * @property CashMovementKind $kind
 * @property int|null $from_account_id
 * @property int|null $to_account_id
 * @property string $amount
 * @property CarbonImmutable $date
 * @property string $reference
 * @property string|null $note
 * @property int|null $reverses_id
 * @property int $created_by
 * @property-read LedgerAccount|null $fromAccount
 * @property-read LedgerAccount|null $toAccount
 * @property-read CashMovement|null $reversal
 */
class CashMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => CashMovementKind::class, 'amount' => 'decimal:3', 'date' => 'immutable_date'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'to_account_id');
    }

    /** @return HasOne<CashMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(CashMovement::class, 'reverses_id');
    }
}
