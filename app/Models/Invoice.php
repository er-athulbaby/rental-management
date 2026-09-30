<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by the billing Actions (amounts and status via forceFill).
 *
 * @property int $id
 * @property string|null $number
 * @property InvoiceType $type
 * @property int $customer_id
 * @property int|null $agreement_id
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $grace_until
 * @property InvoiceStatus $status
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $allocated
 * @property string $credited
 * @property string $balance
 * @property int|null $issued_by
 */
class Invoice extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'balance'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'grace_until' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'subtotal' => 'decimal:3',
            'tax_total' => 'decimal:3',
            'total' => 'decimal:3',
            'allocated' => 'decimal:3',
            'credited' => 'decimal:3',
            'balance' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'number', 'issue_date', 'due_date', 'total', 'tax_total', 'issued_at', 'replaced_by_invoice_id'])
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

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @param  Builder<Invoice>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->whereHas('agreement', fn (Builder $a) => $a->visibleTo($user));
    }

    public function label(): string
    {
        return $this->number ?? __(':status #:id', ['status' => $this->status->label(), 'id' => $this->id]);
    }
}
