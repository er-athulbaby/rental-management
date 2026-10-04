<?php

namespace App\Models;

use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property CarbonImmutable|null $reversed_at
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

    /** @return HasMany<PaymentTender, $this> the parts of a split payment; none otherwise */
    public function tenders(): HasMany
    {
        return $this->hasMany(PaymentTender::class);
    }

    /** "Card 300.000 + Cash 200.000" for a split payment, else the method name. */
    public function methodSummary(): string
    {
        return $this->method === PaymentMethod::Split
            ? $this->tenders->map(fn (PaymentTender $t) => $t->method->label().' '.$t->amount.($t->reference ? ' ('.$t->reference.')' : ''))->implode(' + ')
            : $this->method->label();
    }

    /** @return BelongsTo<Cheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
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

    /** The part of this payment not allocated and not refunded: customer credit (spec §7.2). */
    public function unallocatedFils(): int
    {
        if ($this->status === PaymentStatus::Reversed) {
            return 0;
        }

        return Fils::fromDecimal($this->amount)
            - Fils::fromDecimal((string) ($this->allocations()->sum('amount') ?: '0'))
            - $this->creditRefundedFils();
    }

    /** Σ non-reversed credit refunds of this payment (spec §7.2). */
    public function creditRefundedFils(): int
    {
        return Fils::fromDecimal((string) (Disbursement::query()
            ->where('source_type', Disbursement::SOURCE_PAYMENT)->where('source_id', $this->id)
            ->where('purpose', DisbursementPurpose::CreditRefund)
            ->whereIn('status', [DisbursementStatus::Paid])
            ->sum('amount') ?: '0'));
    }

    /**
     * By where the money went; a payment held entirely as credit goes by where the customer rents.
     *
     * @param  Builder<Payment>  $query
     */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('allocations.line.invoice', fn (Builder $i) => $i->inBuilding($buildingId))
            ->orWhere(fn (Builder $credit) => $credit->whereDoesntHave('allocations')
                ->whereHas('customer.agreements', fn (Builder $a) => $a->inBuilding($buildingId))));
    }
}
