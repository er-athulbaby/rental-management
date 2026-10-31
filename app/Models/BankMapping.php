<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bank reconciliation spec §2 (plan ruling 7): how one money account's statement files are laid out. Columns are 1-based
 * (1 = A); header_row 0 means the file has no header. Written only by ImportBankStatement.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property int $header_row
 * @property int $date_column
 * @property string $date_format d/m/Y | Y-m-d | d-M-Y
 * @property int $description_column
 * @property int|null $reference_column
 * @property string $amount_mode signed | split
 * @property int|null $amount_column
 * @property int|null $debit_column
 * @property int|null $credit_column
 * @property int|null $balance_column
 * @property int $updated_by
 */
class BankMapping extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['header_row' => 'integer', 'date_column' => 'integer', 'description_column' => 'integer', 'reference_column' => 'integer',
            'amount_column' => 'integer', 'debit_column' => 'integer', 'credit_column' => 'integer', 'balance_column' => 'integer'];
    }
}
