<?php

namespace App\Models;

use App\Enums\DisbursementMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ledger money screens spec §4 (plan ruling 9). Written only by RecordVatReturn and ReverseMoneyEntry; never updated or
 * deleted (two triggers). A reversal is its own row pointing at the return it reverses.
 *
 * @property int $id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $direction payment | refund
 * @property DisbursementMethod $method
 * @property string $amount
 * @property CarbonImmutable $date
 * @property string $reference
 * @property string|null $note
 * @property int|null $reverses_id
 * @property int $created_by
 * @property-read VatReturn|null $reversal
 */
class VatReturn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_from' => 'immutable_date', 'period_to' => 'immutable_date', 'method' => DisbursementMethod::class, 'amount' => 'decimal:3', 'date' => 'immutable_date'];
    }

    /** @return HasOne<VatReturn, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(VatReturn::class, 'reverses_id');
    }
}
