<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bank reconciliation plan rulings 1 and 3: a statement line matched to one ledger entry, named by its key (entry_type,
 * entry_id); amount is the entry's net on the account when matched. Never updated; not added or removed while its
 * reconciliation is finished (two guard triggers).
 *
 * @property int $id
 * @property int $bank_reconciliation_id
 * @property int $bank_statement_line_id
 * @property int $ledger_account_id
 * @property string $entry_type a journal source type, or "manual"
 * @property int $entry_id
 * @property string $amount
 * @property int $created_by
 */
class BankMatch extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_id' => 'integer', 'amount' => 'decimal:3'];
    }
}
