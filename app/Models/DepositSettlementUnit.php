<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $deposit_settlement_id
 * @property int $agreement_unit_id
 * @property string $held_amount
 * @property string|null $applied_amount
 * @property string|null $refund_amount
 */
class DepositSettlementUnit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['held_amount' => 'decimal:3', 'applied_amount' => 'decimal:3', 'refund_amount' => 'decimal:3'];
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }
}
