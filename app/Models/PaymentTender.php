<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part of a split payment, e.g. card 300.000 of a 500.000 payment. Write-once (DB triggers).
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentMethod $method
 * @property string $amount
 * @property string|null $reference
 * @property CarbonImmutable|null $created_at
 */
class PaymentTender extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['method', 'amount', 'reference'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount' => 'decimal:3', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
