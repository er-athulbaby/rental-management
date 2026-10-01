<?php

namespace App\Models;

use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by the cheque Actions.
 *
 * @property int $id
 * @property ChequeDirection $direction
 * @property int|null $customer_id
 * @property int|null $agreement_id
 * @property int|null $invoice_id
 * @property string $cheque_no
 * @property string $bank_name
 * @property CarbonImmutable $cheque_date
 * @property string $amount
 * @property ChequeStatus $status
 * @property CarbonImmutable|null $deposited_on
 * @property CarbonImmutable|null $cleared_on
 * @property CarbonImmutable|null $bounced_on
 * @property string|null $bounce_reason
 * @property int|null $payment_id
 * @property int|null $replaced_by_cheque_id
 */
class Cheque extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'direction' => ChequeDirection::class,
            'status' => ChequeStatus::class,
            'cheque_date' => 'immutable_date',
            'deposited_on' => 'immutable_date',
            'cleared_on' => 'immutable_date',
            'bounced_on' => 'immutable_date',
            'returned_on' => 'immutable_date',
            'amount' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'invoice_id', 'cheque_no', 'bank_name', 'cheque_date', 'amount', 'deposited_on', 'cleared_on', 'bounced_on', 'bounce_reason', 'returned_on', 'payment_id', 'replaced_by_cheque_id'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Cheque, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_cheque_id');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @param  Builder<Cheque>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->whereIn('customer_id', Customer::query()->visibleTo($user)->select('id'));
    }
}
