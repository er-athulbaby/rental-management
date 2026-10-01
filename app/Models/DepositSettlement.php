<?php

namespace App\Models;

use App\Enums\DepositSettlementStatus;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.7.
 *
 * @property int $id
 * @property string|null $number
 * @property int $agreement_id
 * @property DepositSettlementStatus $status
 * @property int|null $deductions_invoice_id
 * @property int|null $payment_id
 * @property int $created_by
 * @property-read Agreement $agreement
 */
class DepositSettlement extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => DepositSettlementStatus::class, 'approved_at' => 'immutable_datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'status', 'deductions_invoice_id', 'payment_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return HasMany<DepositSettlementUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(DepositSettlementUnit::class);
    }

    /** @return HasMany<DepositSettlementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepositSettlementLine::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function deductionsInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'deductions_invoice_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Σ refund per unit, set on approval (spec §7.7 step 3). */
    public function refundFils(): int
    {
        return $this->units()->get()->sum(fn (DepositSettlementUnit $u) => Fils::fromDecimal((string) ($u->refund_amount ?? '0')));
    }

    /** Σ non-reversed deposit refunds paid against this settlement. */
    public function refundedFils(): int
    {
        return Fils::fromDecimal((string) (Disbursement::query()
            ->where('source_type', Disbursement::SOURCE_SETTLEMENT)->where('source_id', $this->id)
            ->where('purpose', DisbursementPurpose::DepositRefund)->where('status', DisbursementStatus::Paid)
            ->sum('amount') ?: '0'));
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
