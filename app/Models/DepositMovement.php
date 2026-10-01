<?php

namespace App\Models;

use App\Enums\DepositMovementType;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-once (spec §7.6). Deposit held per agreement unit = Σ amount.
 *
 * @property int $agreement_unit_id
 * @property int|null $owner_contract_id
 * @property CarbonImmutable $posted_at
 * @property DepositMovementType $type
 * @property string $amount
 * @property-read AgreementUnit $agreementUnit
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

    /** Deposit held per agreement unit = Σ amount (spec §7.6). */
    public static function heldFils(int $agreementUnitId, bool $lock = false): int
    {
        $query = self::query()->where('agreement_unit_id', $agreementUnitId);

        return Fils::fromDecimal((string) (($lock ? $query->lockForUpdate() : $query)->sum('amount') ?: '0'));
    }

    /** Spec §7.6, plan ruling 8: applied, refunded and transfer_out copy the stamp of the unit's latest positive movement. */
    public static function ownerContractFor(int $agreementUnitId): ?int
    {
        return self::query()->where('agreement_unit_id', $agreementUnitId)->where('amount', '>', 0)
            ->whereIn('type', ['received', 'opening', 'transfer_in'])->latest('id')->value('owner_contract_id');
    }
}
