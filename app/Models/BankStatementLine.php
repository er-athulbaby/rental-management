<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank reconciliation spec §2: one statement row. Amount + is money in, − money out. Never updated
 * (bank_statement_lines_no_update); deleted only with its upload.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property int $bank_upload_id
 * @property int $row_number
 * @property CarbonImmutable $date
 * @property string $description
 * @property string|null $reference
 * @property string $amount
 * @property string|null $balance
 * @property string $description_hash
 * @property int $occurrence
 */
class BankStatementLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'amount' => 'decimal:3', 'balance' => 'decimal:3', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }
}
