<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * General ledger spec §7: exactly one of debit or credit is above zero (journal_lines_side_chk). Dimensions are nullable
 * (plan ruling 7). Write-once.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $ledger_account_id
 * @property string $debit
 * @property string $credit
 * @property int|null $customer_id
 * @property int|null $owner_id
 * @property int|null $building_id
 * @property int|null $unit_id
 * @property string|null $memo
 * @property-read LedgerAccount $account
 */
class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:3', 'credit' => 'decimal:3'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
