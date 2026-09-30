<?php

namespace App\Models;

use App\Enums\InvoiceChargeType;
use App\Enums\TaxCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int|null $agreement_unit_id
 * @property int|null $unit_id
 * @property InvoiceChargeType $charge_type
 * @property string $description
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $net
 * @property TaxCategory $tax_category
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property int|null $owner_contract_id
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charge_type' => InvoiceChargeType::class,
            'tax_category' => TaxCategory::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'net' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'allocated' => 'decimal:3',
            'credited' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }
}
