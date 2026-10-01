<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by PostPayment and the reversal handler.
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property CarbonImmutable $received_on
 * @property PaymentMethod $method
 * @property string $amount
 * @property int|null $cheque_id
 * @property PaymentStatus $status
 * @property int $recorded_by
 */
class Payment extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_on' => 'immutable_date',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:3',
            'reversed_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'amount', 'method', 'received_on', 'status', 'reversed_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** The part of this payment not (or no longer) allocated: customer credit (spec §7.2). */
    public function unallocatedFils(): int
    {
        if ($this->status === PaymentStatus::Reversed) {
            return 0;
        }

        return Fils::fromDecimal($this->amount) - Fils::fromDecimal((string) ($this->allocations()->sum('amount') ?: '0'));
    }
}
