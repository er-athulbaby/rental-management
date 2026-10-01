<?php

namespace App\Models;

use App\Enums\DepositMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-once (spec §7.6). Deposit held per agreement unit = Σ amount.
 *
 * @property int $agreement_unit_id
 * @property int|null $owner_contract_id
 * @property DepositMovementType $type
 * @property string $amount
 */
class DepositMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => DepositMovementType::class, 'amount' => 'decimal:3', 'posted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }
}
