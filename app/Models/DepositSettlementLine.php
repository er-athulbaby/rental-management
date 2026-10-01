<?php

namespace App\Models;

use App\Enums\DeductionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $deposit_settlement_id
 * @property int $agreement_unit_id
 * @property DeductionType $type
 * @property string $description
 * @property string $amount
 * @property int|null $invoice_line_id
 */
class DepositSettlementLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => DeductionType::class, 'amount' => 'decimal:3'];
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
