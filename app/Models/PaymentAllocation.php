<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-once (spec §7.2, §8.5). Negative rows reverse part or all of an earlier allocation.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $invoice_line_id
 * @property string $amount
 * @property string $tax_amount
 * @property int|null $reverses_allocation_id
 * @property int|null $owner_contract_id
 */
class PaymentAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3', 'tax_amount' => 'decimal:3', 'posted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }

    /** @return BelongsTo<PaymentAllocation, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }
}
