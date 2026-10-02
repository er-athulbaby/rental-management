<?php

namespace App\Models;

use App\Enums\DisbursementMethod;
use App\Enums\DisbursementPurpose;
use App\Enums\DisbursementStatus;
use App\Enums\PayeeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spec §7.5. Written only by the disbursement Actions and approval handlers.
 *
 * @property int $id
 * @property string|null $number
 * @property PayeeType $payee_type
 * @property int $payee_id
 * @property DisbursementPurpose $purpose
 * @property string $amount
 * @property DisbursementMethod $method
 * @property int|null $cheque_id
 * @property string|null $reference
 * @property CarbonImmutable|null $paid_on
 * @property string|null $source_type
 * @property int|null $source_id
 * @property DisbursementStatus $status
 * @property string|null $reason
 * @property int $created_by
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $reversed_at
 */
class Disbursement extends Model
{
    use LogsActivity;

    public const string SOURCE_PAYMENT = 'payment';

    public const string SOURCE_SETTLEMENT = 'deposit_settlement';

    public const string SOURCE_PAYABLE = 'owner_payable';

    public const string SOURCE_STATEMENT = 'owner_statement';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payee_type' => PayeeType::class,
            'purpose' => DisbursementPurpose::class,
            'method' => DisbursementMethod::class,
            'status' => DisbursementStatus::class,
            'amount' => 'decimal:3',
            'paid_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'status', 'amount', 'method', 'paid_on', 'reference', 'reversed_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function payee(): Customer|Owner
    {
        return $this->payee_type === PayeeType::Customer
            ? Customer::withTrashed()->findOrFail($this->payee_id)
            : Owner::withTrashed()->findOrFail($this->payee_id);
    }

    public function source(): ?Model
    {
        return match ($this->source_type) {
            self::SOURCE_PAYMENT => Payment::query()->find($this->source_id),
            self::SOURCE_SETTLEMENT => DepositSettlement::query()->find($this->source_id),
            self::SOURCE_PAYABLE => OwnerPayable::query()->find($this->source_id),
            self::SOURCE_STATEMENT => OwnerStatement::query()->find($this->source_id),
            default => null,
        };
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<Cheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
