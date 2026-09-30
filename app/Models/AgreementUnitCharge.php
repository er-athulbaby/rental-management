<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $agreement_unit_id
 * @property ChargeType $type
 * @property string|null $description
 * @property string $monthly_amount
 * @property TaxCategory $tax_category
 */
class AgreementUnitCharge extends Model
{
    use LogsActivity;

    protected $fillable = ['type', 'description', 'monthly_amount', 'tax_category'];

    protected function casts(): array
    {
        return ['type' => ChargeType::class, 'tax_category' => TaxCategory::class, 'monthly_amount' => 'decimal:3'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'description', 'monthly_amount', 'tax_category'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }
}
