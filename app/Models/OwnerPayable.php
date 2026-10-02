<?php

namespace App\Models;

use App\Enums\OwnerPayableStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.8.
 *
 * @property int $id
 * @property int $owner_contract_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_date
 * @property string $amount
 * @property OwnerPayableStatus $status
 * @property int|null $disbursement_id
 * @property int|null $replaced_by_payable_id
 */
class OwnerPayable extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_date' => 'immutable_date',
            'amount' => 'decimal:3',
            'status' => OwnerPayableStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'disbursement_id', 'replaced_by_payable_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    /** @return BelongsTo<Disbursement, $this> */
    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class);
    }

    /** @return BelongsTo<OwnerPayable, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_payable_id');
    }
}
