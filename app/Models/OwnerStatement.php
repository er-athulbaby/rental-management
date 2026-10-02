<?php

namespace App\Models;

use App\Enums\OwnerStatementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.9.
 *
 * @property int $id
 * @property string|null $number
 * @property int $owner_contract_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $cutoff_at
 * @property string $opening_balance
 * @property string $fee_base
 * @property string $fee_amount
 * @property string $fee_tax
 * @property string $closing_balance
 * @property OwnerStatementStatus $status
 * @property int $created_by
 * @property int|null $finalised_by
 * @property CarbonImmutable|null $finalised_at
 * @property-read OwnerContract $contract
 */
class OwnerStatement extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            'opening_balance' => 'decimal:3',
            'fee_base' => 'decimal:3',
            'fee_amount' => 'decimal:3',
            'fee_tax' => 'decimal:3',
            'closing_balance' => 'decimal:3',
            'status' => OwnerStatementStatus::class,
            'finalised_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'number', 'closing_balance'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** The contract's statement for the month before this one's, if any (statements may skip quiet months). */
    public function previous(): ?self
    {
        return self::query()->where('owner_contract_id', $this->owner_contract_id)
            ->where('period_start', '<', $this->period_start->toDateString())->orderByDesc('period_start')->first();
    }

    public function label(): string
    {
        return $this->number ?? __('Draft statement :m', ['m' => $this->period_start->format('M Y')]);
    }
}
