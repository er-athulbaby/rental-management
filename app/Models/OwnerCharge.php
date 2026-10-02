<?php

namespace App\Models;

use App\Enums\OwnerChargeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec §7.9. Write-once (triggers).
 *
 * @property int $id
 * @property int $owner_contract_id
 * @property int|null $owner_statement_id
 * @property OwnerChargeType $type
 * @property string $net
 * @property string $tax_amount
 * @property string $amount
 * @property CarbonImmutable $posted_at
 * @property int $created_by
 */
class OwnerCharge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OwnerChargeType::class,
            'net' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'amount' => 'decimal:3',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }
}
